<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal\Storage;

use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\Id;
use YorvoID\Core\Principal\AgentProfile;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;
use YorvoID\Core\Storage\StorageException;

/**
 * @api
 */
final readonly class DbalPrincipalStore implements PrincipalStore
{
    public const string TABLE = 'principal';

    private const array TIMES = ['created_at' => Types::DATETIMETZ_IMMUTABLE, 'updated_at' => Types::DATETIMETZ_IMMUTABLE, 'status_changed_at' => Types::DATETIMETZ_IMMUTABLE];

    public function __construct(
        private Database $database,
    ) {
    }

    public function add(Principal $principal): void
    {
        if (!$this->database->insertUnlessExists(self::TABLE, [
            'id' => $principal->id->id->toString(),
            'realm' => $principal->realm->name,
            'kind' => $principal->kind->value,
            'status' => $principal->status->value,
            'created_at' => $principal->createdAt,
            'updated_at' => $principal->updatedAt,
            'status_changed_at' => $principal->statusChangedAt,
            'status_version' => $principal->statusVersion,
            'owner_id' => $principal->owner?->id->toString(),
            'profile' => null === $principal->profile ? null : json_encode($principal->profile->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        ], ['id'], self::TIMES)) {
            throw new StorageException(\sprintf('A principal with the ID %s exists.', $principal->id));
        }
    }

    public function get(Realm $realm, PrincipalId $id): ?Principal
    {
        $row = $this->database->connection->fetchAssociative(
            \sprintf('SELECT kind, status, created_at, updated_at, status_changed_at, status_version, owner_id, profile FROM %s WHERE realm = ? AND id = ?', $this->database->table(self::TABLE)),
            [$realm->name, $id->id->toString()],
        );

        if (false === $row) {
            return null;
        }

        return new Principal(
            $id,
            $realm,
            PrincipalKind::from(Row::string($row, 'kind')),
            PrincipalStatus::from(Row::string($row, 'status')),
            $this->time($row['created_at']),
            $this->time($row['updated_at']),
            $this->time($row['status_changed_at']),
            Row::int($row, 'status_version'),
            null === ($row['owner_id'] ?? null) ? null : new PrincipalId(Id::fromString(Row::string($row, 'owner_id'))),
            null === ($row['profile'] ?? null) ? null : self::profile(Row::string($row, 'profile')),
        );
    }

    public function ownsAgents(Realm $realm, PrincipalId $owner): bool
    {
        return false !== $this->database->connection->fetchOne(
            \sprintf('SELECT 1 FROM %s WHERE realm = ? AND owner_id = ? AND status <> ? LIMIT 1', $this->database->table(self::TABLE)),
            [$realm->name, $owner->id->toString(), PrincipalStatus::Deleted->value],
        );
    }

    public function changeOwner(Principal $before, Principal $after): bool
    {
        if (null === $before->owner || null === $after->owner) {
            return false;
        }

        return 1 === $this->database->connection->update(
            $this->database->table(self::TABLE),
            ['owner_id' => $after->owner->id->toString(), 'updated_at' => $after->updatedAt],
            ['realm' => $before->realm->name, 'id' => $before->id->id->toString(), 'owner_id' => $before->owner->id->toString()],
            self::TIMES,
        );
    }

    private static function profile(string $json): AgentProfile
    {
        try {
            $data = json_decode($json, true, 3, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException('A stored agent profile isn\'t valid JSON.', previous: $e);
        }

        return \is_array($data) ? AgentProfile::fromArray($data) : throw new \UnexpectedValueException('A stored agent profile isn\'t an object.');
    }

    public function hasActive(Realm $realm): bool
    {
        return false !== $this->database->connection->fetchOne(
            \sprintf('SELECT 1 FROM %s WHERE realm = ? AND status = ? LIMIT 1', $this->database->table(self::TABLE)),
            [$realm->name, PrincipalStatus::Active->value],
        );
    }

    public function lock(Realm $realm, PrincipalId $id): void
    {
        $this->inTransaction();

        // A write, not FOR UPDATE: under REPEATABLE READ, whoever waited for it then conflicts (D-52).
        $this->database->connection->executeStatement(\sprintf('UPDATE %s SET lock_count = lock_count + 1 WHERE realm = ? AND id = ?', $this->database->table(self::TABLE)), [$realm->name, $id->id->toString()]);
    }

    private function inTransaction(): void
    {
        if (!$this->database->connection->isTransactionActive()) {
            throw new IntegratorError('Principals were locked outside a transaction.', 'Lock them inside Transactions::run(), before reading what the change depends on.', 'concepts/storage.md#locks');
        }
    }

    public function changeStatus(Principal $before, Principal $after): bool
    {
        return 1 === $this->database->connection->update(
            $this->database->table(self::TABLE),
            ['status' => $after->status->value, 'updated_at' => $after->updatedAt, 'status_changed_at' => $after->statusChangedAt, 'status_version' => $after->statusVersion],
            ['realm' => $before->realm->name, 'id' => $before->id->id->toString(), 'status_version' => $before->statusVersion],
            self::TIMES,
        );
    }

    private function time(mixed $value): \DateTimeImmutable
    {
        $time = $this->database->connection->convertToPHPValue($value, Types::DATETIMETZ_IMMUTABLE);
        \assert($time instanceof \DateTimeImmutable);

        return $time;
    }
}
