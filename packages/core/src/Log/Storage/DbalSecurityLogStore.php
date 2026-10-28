<?php

declare(strict_types=1);

namespace YorvoID\Core\Log\Storage;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;
use YorvoID\Core\Storage\SqlPlatform;

/**
 * The security log in two tables: the entries, and each realm's head (its last sequence and hash).
 * Appending locks the realm's head row until the transaction ends, which serializes appends across
 * processes; the primary key (realm, sequence) makes a fork impossible even if that failed.
 *
 * @api
 */
final readonly class DbalSecurityLogStore implements SecurityLogStore
{
    public const string TABLE = 'security_log';

    public const string HEAD_TABLE = 'security_log_head';

    private const string COLUMNS = 'sequence, id, recorded_at, action, outcome, origin_kind, origin_detail, correlation_id, actor, subject_type, subject_id, details, algorithm, previous_hash, hash';

    public function __construct(
        private Database $database,
    ) {
    }

    public function append(Realm $realm, \Closure $build): LogEntry
    {
        $connection = $this->database->connection;

        if (!$connection->isTransactionActive()) {
            throw new IntegratorError('The security log was appended to outside a transaction.', 'Append through SecurityLog::record(), which opens one, or inside Transactions::run().', 'concepts/security-log.md');
        }

        $head = $this->database->table(self::HEAD_TABLE);

        $connection->executeStatement(match ($this->database->platform) {
            SqlPlatform::PostgreSQL => \sprintf('INSERT INTO %s (realm, sequence, hash) VALUES (?, 0, NULL) ON CONFLICT (realm) DO NOTHING', $head),
            SqlPlatform::SQLite => \sprintf('INSERT OR IGNORE INTO %s (realm, sequence, hash) VALUES (?, 0, NULL)', $head),
        }, [$realm->name]);

        // On PostgreSQL, the row stays locked until the transaction ends. SQLite writes one transaction
        // at a time anyway.
        $current = $connection->fetchAssociative(\sprintf('SELECT sequence, hash FROM %s WHERE realm = ?%s', $head, SqlPlatform::PostgreSQL === $this->database->platform ? ' FOR UPDATE' : ''), [$realm->name]);
        \assert(\is_array($current));

        $entry = $build(Row::int($current, 'sequence') + 1, Row::nullableString($current, 'hash'));

        $connection->insert($this->database->table(self::TABLE), [
            'realm' => $realm->name,
            'sequence' => $entry->sequence,
            'id' => $entry->id->toRfc4122(),
            'recorded_at' => $entry->recordedAt,
            'action' => $entry->action,
            'outcome' => $entry->outcome->value,
            'origin_kind' => $entry->origin->kind->value,
            'origin_detail' => $entry->origin->detail,
            'correlation_id' => $entry->origin->correlationId,
            'actor' => $entry->actor?->uuid->toRfc4122(),
            'subject_type' => $entry->subjectType,
            'subject_id' => $entry->subjectId,
            'details' => $entry->details,
            'algorithm' => $entry->algorithm,
            'previous_hash' => $entry->previousHash,
            'hash' => $entry->hash,
        ], ['recorded_at' => Types::DATETIMETZ_IMMUTABLE, 'details' => Types::JSON]);

        $connection->executeStatement(\sprintf('UPDATE %s SET sequence = ?, hash = ? WHERE realm = ?', $head), [$entry->sequence, $entry->hash, $realm->name]);

        return $entry;
    }

    public function entries(Realm $realm, int $fromSequence): iterable
    {
        $rows = $this->database->connection->iterateAssociative(
            \sprintf('SELECT %s FROM %s WHERE realm = ? AND sequence >= ? ORDER BY sequence', self::COLUMNS, $this->database->table(self::TABLE)),
            [$realm->name, $fromSequence],
        );

        foreach ($rows as $row) {
            yield $this->entry($realm, $row);
        }
    }

    public function head(Realm $realm): ?array
    {
        $row = $this->database->connection->fetchAssociative(\sprintf('SELECT sequence, hash FROM %s WHERE realm = ?', $this->database->table(self::HEAD_TABLE)), [$realm->name]);

        if (false === $row || null === $row['hash']) {
            return null;
        }

        return ['sequence' => Row::int($row, 'sequence'), 'hash' => Row::string($row, 'hash')];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function entry(Realm $realm, array $row): LogEntry
    {
        $platform = $this->database->connection->getDatabasePlatform();
        $recordedAt = Type::getType(Types::DATETIMETZ_IMMUTABLE)->convertToPHPValue($row['recorded_at'], $platform);
        $details = Type::getType(Types::JSON)->convertToPHPValue($row['details'], $platform);
        \assert($recordedAt instanceof \DateTimeImmutable && \is_array($details));
        $actor = Row::nullableString($row, 'actor');

        return new LogEntry(
            $realm,
            Row::int($row, 'sequence'),
            Uuid::fromString(Row::string($row, 'id')),
            $recordedAt,
            Row::string($row, 'action'),
            Outcome::from(Row::string($row, 'outcome')),
            new Origin(OriginKind::from(Row::string($row, 'origin_kind')), Row::nullableString($row, 'origin_detail'), Row::string($row, 'correlation_id')),
            null === $actor ? null : PrincipalId::fromString($actor),
            Row::nullableString($row, 'subject_type'),
            Row::nullableString($row, 'subject_id'),
            $details,
            Row::string($row, 'algorithm'),
            Row::string($row, 'previous_hash'),
            Row::string($row, 'hash'),
        );
    }
}
