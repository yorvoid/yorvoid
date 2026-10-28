<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential\Storage;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Credential\CredentialKind;
use YorvoID\Authn\Credential\CredentialStatus;
use YorvoID\Authn\Credential\CredentialStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;
use YorvoID\Core\Storage\StorageException;

/**
 * @api
 */
final readonly class DbalCredentialStore implements CredentialStore
{
    public const string TABLE = 'credential';

    private const string COLUMNS = 'id, principal_id, kind, label, created_at, last_used_at, status';

    private const array TIMES = ['created_at' => Types::DATETIMETZ_IMMUTABLE, 'last_used_at' => Types::DATETIMETZ_IMMUTABLE];

    public function __construct(
        private Database $database,
    ) {
    }

    public function add(Credential $credential): void
    {
        try {
            $this->database->connection->insert($this->database->table(self::TABLE), [
                'id' => $credential->id->uuid->toRfc4122(),
                'realm' => $credential->realm->name,
                'principal_id' => $credential->principalId->uuid->toRfc4122(),
                'kind' => $credential->kind->value,
                'label' => $credential->label,
                'created_at' => $credential->createdAt,
                'last_used_at' => $credential->lastUsedAt,
                'status' => $credential->status->value,
            ], self::TIMES);
        } catch (UniqueConstraintViolationException $exception) {
            throw new StorageException(\sprintf('A credential with the ID %s exists.', $credential->id), previous: $exception);
        }
    }

    public function get(Realm $realm, CredentialId $id): ?Credential
    {
        $row = $this->database->connection->fetchAssociative(\sprintf('SELECT %s FROM %s WHERE realm = ? AND id = ?', self::COLUMNS, $this->database->table(self::TABLE)), [$realm->name, $id->uuid->toRfc4122()]);

        return false === $row ? null : $this->credential($realm, $row);
    }

    public function of(Realm $realm, PrincipalId $principalId): array
    {
        $credentials = [];

        foreach ($this->database->connection->iterateAssociative(
            \sprintf('SELECT %s FROM %s WHERE realm = ? AND principal_id = ? ORDER BY created_at, id', self::COLUMNS, $this->database->table(self::TABLE)),
            [$realm->name, $principalId->uuid->toRfc4122()],
        ) as $row) {
            $credentials[] = $this->credential($realm, $row);
        }

        return $credentials;
    }

    public function update(Credential $credential): void
    {
        $this->database->connection->update(
            $this->database->table(self::TABLE),
            ['label' => $credential->label, 'last_used_at' => $credential->lastUsedAt, 'status' => $credential->status->value],
            ['realm' => $credential->realm->name, 'id' => $credential->id->uuid->toRfc4122()],
            self::TIMES,
        );
    }

    public function remove(Realm $realm, CredentialId $id): bool
    {
        return 1 === $this->database->connection->delete($this->database->table(self::TABLE), ['realm' => $realm->name, 'id' => $id->uuid->toRfc4122()]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function credential(Realm $realm, array $row): Credential
    {
        return new Credential(
            CredentialId::fromString(Row::string($row, 'id')),
            $realm,
            PrincipalId::fromString(Row::string($row, 'principal_id')),
            CredentialKind::from(Row::string($row, 'kind')),
            Row::string($row, 'label'),
            $this->time($row['created_at']),
            null === $row['last_used_at'] ? null : $this->time($row['last_used_at']),
            CredentialStatus::from(Row::string($row, 'status')),
        );
    }

    private function time(mixed $value): \DateTimeImmutable
    {
        $time = Type::getType(Types::DATETIMETZ_IMMUTABLE)->convertToPHPValue($value, $this->database->connection->getDatabasePlatform());
        \assert($time instanceof \DateTimeImmutable);

        return $time;
    }
}
