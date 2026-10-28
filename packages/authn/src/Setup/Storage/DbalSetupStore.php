<?php

declare(strict_types=1);

namespace YorvoID\Authn\Setup\Storage;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use YorvoID\Authn\Setup\SetupState;
use YorvoID\Authn\Setup\SetupStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;

/**
 * @api
 */
final readonly class DbalSetupStore implements SetupStore
{
    public const string TABLE = 'realm_setup';

    public function __construct(
        private Database $database,
    ) {
    }

    public function get(Realm $realm): ?SetupState
    {
        $row = $this->database->connection->fetchAssociative(
            \sprintf('SELECT code_hash, created_at, expires_at, claimed_at, claimed_by FROM %s WHERE realm = ?', $this->database->table(self::TABLE)),
            [$realm->name],
        );

        if (false === $row) {
            return null;
        }

        $claimedBy = Row::nullableString($row, 'claimed_by');

        return new SetupState($realm, Row::string($row, 'code_hash'), $this->time($row['created_at']), $this->time($row['expires_at']), null === $row['claimed_at'] ? null : $this->time($row['claimed_at']), null === $claimedBy ? null : PrincipalId::fromString($claimedBy));
    }

    public function replaceCode(Realm $realm, string $codeHash, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt): bool
    {
        $table = $this->database->table(self::TABLE);
        $this->database->connection->executeStatement(\sprintf('DELETE FROM %s WHERE realm = ? AND claimed_at IS NULL', $table), [$realm->name]);

        // The primary key refuses this if a claimed row remains.
        return 1 === $this->database->connection->executeStatement(
            \sprintf('INSERT INTO %s (realm, code_hash, created_at, expires_at) SELECT ?, ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM %s WHERE realm = ?)', $table, $table),
            [$realm->name, $codeHash, $createdAt, $expiresAt, $realm->name],
            [2 => Types::DATETIMETZ_IMMUTABLE, 3 => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    public function claim(Realm $realm, string $codeHash, PrincipalId $by, \DateTimeImmutable $at): bool
    {
        return 1 === $this->database->connection->executeStatement(
            \sprintf('UPDATE %s SET claimed_at = ?, claimed_by = ? WHERE realm = ? AND code_hash = ? AND claimed_at IS NULL', $this->database->table(self::TABLE)),
            [$at, $by->uuid->toRfc4122(), $realm->name, $codeHash],
            [0 => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    private function time(mixed $value): \DateTimeImmutable
    {
        $time = Type::getType(Types::DATETIMETZ_IMMUTABLE)->convertToPHPValue($value, $this->database->connection->getDatabasePlatform());
        \assert($time instanceof \DateTimeImmutable);

        return $time;
    }
}
