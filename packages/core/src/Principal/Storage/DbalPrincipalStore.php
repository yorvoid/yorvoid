<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal\Storage;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
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
        try {
            $this->database->connection->insert($this->database->table(self::TABLE), [
                'id' => $principal->id->uuid->toRfc4122(),
                'realm' => $principal->realm->name,
                'kind' => $principal->kind->value,
                'status' => $principal->status->value,
                'created_at' => $principal->createdAt,
                'updated_at' => $principal->updatedAt,
                'status_changed_at' => $principal->statusChangedAt,
                'status_version' => $principal->statusVersion,
            ], self::TIMES);
        } catch (UniqueConstraintViolationException $exception) {
            throw new StorageException(\sprintf('A principal with the ID %s exists.', $principal->id), previous: $exception);
        }
    }

    public function get(Realm $realm, PrincipalId $id): ?Principal
    {
        $row = $this->database->connection->fetchAssociative(
            \sprintf('SELECT kind, status, created_at, updated_at, status_changed_at, status_version FROM %s WHERE realm = ? AND id = ?', $this->database->table(self::TABLE)),
            [$realm->name, $id->uuid->toRfc4122()],
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
        );
    }

    public function changeStatus(Principal $before, Principal $after): bool
    {
        return 1 === $this->database->connection->update(
            $this->database->table(self::TABLE),
            ['status' => $after->status->value, 'updated_at' => $after->updatedAt, 'status_changed_at' => $after->statusChangedAt, 'status_version' => $after->statusVersion],
            ['realm' => $before->realm->name, 'id' => $before->id->uuid->toRfc4122(), 'status_version' => $before->statusVersion],
            self::TIMES,
        );
    }

    private function time(mixed $value): \DateTimeImmutable
    {
        $time = Type::getType(Types::DATETIMETZ_IMMUTABLE)->convertToPHPValue($value, $this->database->connection->getDatabasePlatform());
        \assert($time instanceof \DateTimeImmutable);

        return $time;
    }
}
