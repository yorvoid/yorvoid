<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal\Storage;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Principal\Identifier;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\IdentifierStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;

/**
 * Uniqueness is the database's: two transactions adding the same email address at the same moment can't
 * both succeed.
 *
 * @api
 */
final readonly class DbalIdentifierStore implements IdentifierStore
{
    public const string TABLE = 'principal_identifier';

    private const string COLUMNS = 'principal_id, kind, value, created_at, verified_at';

    public function __construct(
        private Database $database,
    ) {
    }

    public function add(Identifier $identifier): bool
    {
        try {
            $this->database->connection->insert($this->database->table(self::TABLE), [
                'realm' => $identifier->realm->name,
                'kind' => $identifier->kind->value,
                'normalized' => $identifier->normalized,
                'value' => $identifier->value,
                'principal_id' => $identifier->principalId->uuid->toRfc4122(),
                'created_at' => $identifier->createdAt,
                'verified_at' => $identifier->verifiedAt,
            ], ['created_at' => Types::DATETIMETZ_IMMUTABLE, 'verified_at' => Types::DATETIMETZ_IMMUTABLE]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function remove(Realm $realm, PrincipalId $principalId, IdentifierKind $kind): void
    {
        $this->database->connection->delete($this->database->table(self::TABLE), ['realm' => $realm->name, 'principal_id' => $principalId->uuid->toRfc4122(), 'kind' => $kind->value]);
    }

    public function removeAll(Realm $realm, PrincipalId $principalId): void
    {
        $this->database->connection->delete($this->database->table(self::TABLE), ['realm' => $realm->name, 'principal_id' => $principalId->uuid->toRfc4122()]);
    }

    public function find(Realm $realm, IdentifierKind $kind, string $normalized): ?Identifier
    {
        $row = $this->database->connection->fetchAssociative(
            \sprintf('SELECT %s FROM %s WHERE realm = ? AND kind = ? AND normalized = ?', self::COLUMNS, $this->database->table(self::TABLE)),
            [$realm->name, $kind->value, $normalized],
        );

        return false === $row ? null : $this->identifier($realm, $row);
    }

    public function of(Realm $realm, PrincipalId $principalId): array
    {
        $identifiers = [];

        foreach ($this->database->connection->iterateAssociative(
            \sprintf('SELECT %s FROM %s WHERE realm = ? AND principal_id = ? ORDER BY kind', self::COLUMNS, $this->database->table(self::TABLE)),
            [$realm->name, $principalId->uuid->toRfc4122()],
        ) as $row) {
            $identifier = $this->identifier($realm, $row);
            $identifiers[$identifier->kind->value] = $identifier;
        }

        return $identifiers;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function identifier(Realm $realm, array $row): Identifier
    {
        $platform = $this->database->connection->getDatabasePlatform();
        $time = Type::getType(Types::DATETIMETZ_IMMUTABLE);
        $createdAt = $time->convertToPHPValue($row['created_at'], $platform);
        $verifiedAt = $time->convertToPHPValue($row['verified_at'], $platform);
        \assert($createdAt instanceof \DateTimeImmutable && (null === $verifiedAt || $verifiedAt instanceof \DateTimeImmutable));

        return new Identifier($realm, PrincipalId::fromString(Row::string($row, 'principal_id')), IdentifierKind::from(Row::string($row, 'kind')), Row::string($row, 'value'), $createdAt, $verifiedAt);
    }
}
