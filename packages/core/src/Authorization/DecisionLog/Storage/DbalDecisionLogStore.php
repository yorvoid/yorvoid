<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\DecisionLog\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Authorization\DecisionLog\DecisionLogStore;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Authorization\DecisionLog\WritesApart;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;
use YorvoID\Core\Storage\SqlPlatform;

/**
 * Decisions in the database: plain inserts, many rows in one statement, no chain and no head row, so
 * nothing waits for a lock. Each row keeps the record's canonical form as written, which its hash is
 * over, and the columns it's found and pruned by.
 *
 * @api
 */
final readonly class DbalDecisionLogStore implements DecisionLogStore, WritesApart
{
    public const string TABLE = 'authorization_decision';

    /** How far each realm's records are digested in its chain */
    public const string DIGESTS = 'authorization_decision_digest';

    /** The time before which records were pruned: one row */
    public const string PRUNED = 'authorization_decision_pruned';

    /** Rows per statement: 8 parameters each, well within every platform's limit */
    private const int BATCH = 100;

    private const array COLUMNS = ['id', 'realm', 'decided_at', 'name', 'actor', 'verdict', 'content', 'hash'];

    /**
     * @param Connection|null $writes A connection of its own to the same database, for writing records
     *                                outside the application's transactions
     */
    public function __construct(
        private Database $database,
        private ?Connection $writes = null,
    ) {
    }

    public function writesApart(): bool
    {
        return null !== $this->writes;
    }

    public function add(array $records, ?bool $apart = null): void
    {
        $connection = $this->writes ?? $this->database->connection;
        // Written apart from any caller's transaction: by the database's clock at the statement, for a
        // digest to count it. Inside one, nothing: its citation covers it (I2 of D-42). DecisionLog says so
        // for what it writes apart: in a hook after the application's commit, DBAL still counts the
        // transaction open, so it can't be told from here (C1).
        $apart ??= null !== $this->writes || !$this->database->connection->isTransactionActive();
        $written = $apart ? $this->clock() : 'NULL';

        foreach (array_chunk($records, self::BATCH) as $batch) {
            $parameters = [];

            foreach ($batch as $record) {
                array_push($parameters, $record->id, $record->realm->name, $connection->convertToDatabaseValue($record->at, Types::DATETIMETZ_IMMUTABLE), $record->name, $record->actor, $record->verdict, $record->json(), $record->hash());
            }

            $row = '('.implode(', ', array_map(static fn (): string => '?', self::COLUMNS)).', '.$written.')';
            $connection->executeStatement(\sprintf('INSERT INTO %s (%s, written_us) VALUES %s', $this->table(), implode(', ', self::COLUMNS), implode(', ', array_map(static fn (): string => $row, $batch))), $parameters);
        }
    }

    public function find(Realm $realm, string $id): ?DecisionRecord
    {
        $row = $this->database->connection->fetchAssociative(\sprintf('SELECT content, hash FROM %s WHERE id = ? AND realm = ?', $this->table()), [$id, $realm->name]);

        if (false === $row) {
            return null;
        }

        $record = DecisionRecord::fromJson(Row::string($row, 'content'));

        if (!$record->realm->equals($realm) || $record->id !== $id) {
            throw new \UnexpectedValueException(\sprintf('The stored decision %s doesn\'t match its row.', $id));
        }

        return $record;
    }

    public function prune(\DateTimeImmutable $before): int
    {
        return $this->database->connection->transactional(function () use ($before): int {
            $removed = (int) $this->database->connection->executeStatement(\sprintf('DELETE FROM %s WHERE decided_at < ?', $this->table()), [$before], [Types::DATETIMETZ_IMMUTABLE]);
            $us = (int) $before->format('Uu');
            $table = $this->database->table(self::PRUNED);

            if (0 === $this->database->connection->executeStatement(\sprintf('UPDATE %s SET before_us = ? WHERE id = 1 AND before_us < ?', $table), [$us, $us])
                && false === $this->database->connection->fetchOne(\sprintf('SELECT before_us FROM %s WHERE id = 1', $table))) {
                $this->database->connection->insert($table, ['id' => 1, 'before_us' => $us]);
            }

            return $removed;
        });
    }

    public function now(): int
    {
        return Row::int(['now' => $this->database->connection->fetchOne('SELECT '.$this->clock())], 'now');
    }

    public function written(Realm $realm, int $after, int $until): array
    {
        $rows = $this->database->connection->fetchAllNumeric(\sprintf('SELECT id, content FROM %s WHERE realm = ? AND written_us > ? AND written_us <= ? ORDER BY written_us, id', $this->table()), [$realm->name, $after, $until]);

        // The hash of what's stored now, not the hash column: a changed record doesn't match its digest.
        return array_map(static function (array $row): array {
            $content = Row::string(['content' => $row[1]], 'content');

            try {
                $hash = DecisionRecord::fromJson($content)->hash();
            } catch (\UnexpectedValueException) {
                $hash = 'unreadable:'.hash('sha256', $content);
            }

            return [Row::string(['id' => $row[0]], 'id'), $hash];
        }, $rows);
    }

    public function digestedUntil(Realm $realm): int
    {
        $until = $this->database->connection->fetchOne(\sprintf('SELECT digested_until_us FROM %s WHERE realm = ?', $this->database->table(self::DIGESTS)), [$realm->name]);

        return false === $until ? 0 : Row::int(['until' => $until], 'until');
    }

    public function digested(Realm $realm, int $until): void
    {
        $table = $this->database->table(self::DIGESTS);

        if (0 === $this->database->connection->executeStatement(\sprintf('UPDATE %s SET digested_until_us = ? WHERE realm = ?', $table), [$until, $realm->name])) {
            $this->database->connection->insert($table, ['realm' => $realm->name, 'digested_until_us' => $until]);
        }
    }

    public function prunedBefore(): int
    {
        $before = $this->database->connection->fetchOne(\sprintf('SELECT before_us FROM %s WHERE id = 1', $this->database->table(self::PRUNED)));

        return false === $before ? 0 : Row::int(['before' => $before], 'before');
    }

    /**
     * The database's clock, in microseconds since the epoch, at the statement: not the transaction's
     * start, which on PostgreSQL is what now() gives (I2 of D-42).
     */
    private function clock(): string
    {
        return SqlPlatform::PostgreSQL === $this->database->platform
            ? 'CAST(EXTRACT(EPOCH FROM clock_timestamp()) * 1000000 AS BIGINT)'
            : "CAST((julianday('now') - 2440587.5) * 86400000000 AS INTEGER)";
    }

    private function table(): string
    {
        return $this->database->table(self::TABLE);
    }
}
