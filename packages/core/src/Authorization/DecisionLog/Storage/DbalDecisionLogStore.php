<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\DecisionLog\Storage;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Authorization\DecisionLog\DecisionLogStore;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;

/**
 * Decisions in the database: plain inserts, many rows in one statement, no chain and no head row, so
 * nothing waits for a lock. Each row keeps the record's canonical form as written, which its hash is
 * over, and the columns it's found and pruned by.
 *
 * @api
 */
final readonly class DbalDecisionLogStore implements DecisionLogStore
{
    public const string TABLE = 'authorization_decision';

    /** Rows per statement: 8 parameters each, well within every platform's limit */
    private const int BATCH = 100;

    private const array COLUMNS = ['id', 'realm', 'decided_at', 'name', 'actor', 'verdict', 'content', 'hash'];

    public function __construct(
        private Database $database,
    ) {
    }

    public function add(array $records): void
    {
        $connection = $this->database->connection;
        $time = Type::getType(Types::DATETIMETZ_IMMUTABLE);
        $platform = $connection->getDatabasePlatform();

        foreach (array_chunk($records, self::BATCH) as $batch) {
            $parameters = [];

            foreach ($batch as $record) {
                array_push($parameters, $record->id, $record->realm->name, $time->convertToDatabaseValue($record->at, $platform), $record->name, $record->actor, $record->verdict, $record->json(), $record->hash());
            }

            $row = '('.implode(', ', array_map(static fn (): string => '?', self::COLUMNS)).')';
            $connection->executeStatement(\sprintf('INSERT INTO %s (%s) VALUES %s', $this->table(), implode(', ', self::COLUMNS), implode(', ', array_map(static fn (): string => $row, $batch))), $parameters);
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
        return (int) $this->database->connection->executeStatement(\sprintf('DELETE FROM %s WHERE decided_at < ?', $this->table()), [$before], [Types::DATETIMETZ_IMMUTABLE]);
    }

    private function table(): string
    {
        return $this->database->table(self::TABLE);
    }
}
