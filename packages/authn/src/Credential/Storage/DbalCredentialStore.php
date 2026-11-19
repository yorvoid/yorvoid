<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential\Storage;

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
        if (!$this->database->insertUnlessExists(self::TABLE, [
            'id' => $credential->id->uuid->toRfc4122(),
            'realm' => $credential->realm->name,
            'principal_id' => $credential->principalId->uuid->toRfc4122(),
            'kind' => $credential->kind->value,
            'label' => $credential->label,
            'created_at' => $credential->createdAt,
            'last_used_at' => $credential->lastUsedAt,
            'status' => $credential->status->value,
        ], ['id'], self::TIMES)) {
            throw new StorageException(\sprintf('A credential with the ID %s exists.', $credential->id));
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

    public function rename(Realm $realm, CredentialId $id, string $label): bool
    {
        return 1 === $this->database->connection->update($this->database->table(self::TABLE), ['label' => $label], self::key($realm, $id));
    }

    public function markUsed(Realm $realm, CredentialId $id, \DateTimeImmutable $at): bool
    {
        return 1 === $this->database->connection->update(
            $this->database->table(self::TABLE),
            ['last_used_at' => $at],
            [...self::key($realm, $id), 'status' => CredentialStatus::Active->value],
            self::TIMES,
        );
    }

    public function disable(Realm $realm, CredentialId $id): bool
    {
        return 1 === $this->database->connection->update($this->database->table(self::TABLE), ['status' => CredentialStatus::Disabled->value], self::key($realm, $id));
    }

    public function remove(Realm $realm, CredentialId $id): bool
    {
        return 1 === $this->database->connection->delete($this->database->table(self::TABLE), self::key($realm, $id));
    }

    /**
     * @return array{realm: string, id: string}
     */
    private static function key(Realm $realm, CredentialId $id): array
    {
        return ['realm' => $realm->name, 'id' => $id->uuid->toRfc4122()];
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
        $time = $this->database->connection->convertToPHPValue($value, Types::DATETIMETZ_IMMUTABLE);
        \assert($time instanceof \DateTimeImmutable);

        return $time;
    }
}
