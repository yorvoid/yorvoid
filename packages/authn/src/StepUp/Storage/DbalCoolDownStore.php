<?php

declare(strict_types=1);

namespace YorvoID\Authn\StepUp\Storage;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use YorvoID\Authn\StepUp\CoolDownStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;

/**
 * @api
 */
final readonly class DbalCoolDownStore implements CoolDownStore
{
    public const string TABLE = 'recovery_cool_down';

    public function __construct(
        private Database $database,
    ) {
    }

    public function extend(Realm $realm, PrincipalId $principalId, \DateTimeImmutable $until): void
    {
        $table = $this->database->table(self::TABLE);
        $principal = $principalId->uuid->toRfc4122();
        $this->database->connection->executeStatement(
            \sprintf('UPDATE %s SET ends_at = ? WHERE realm = ? AND principal_id = ? AND ends_at < ?', $table),
            [$until, $realm->name, $principal, $until],
            [0 => Types::DATETIMETZ_IMMUTABLE, 3 => Types::DATETIMETZ_IMMUTABLE],
        );
        $this->database->connection->executeStatement(
            \sprintf('INSERT INTO %s (realm, principal_id, ends_at) SELECT ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM %s WHERE realm = ? AND principal_id = ?)', $table, $table),
            [$realm->name, $principal, $until, $realm->name, $principal],
            [2 => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    public function until(Realm $realm, PrincipalId $principalId): ?\DateTimeImmutable
    {
        $value = $this->database->connection->fetchOne(\sprintf('SELECT ends_at FROM %s WHERE realm = ? AND principal_id = ?', $this->database->table(self::TABLE)), [$realm->name, $principalId->uuid->toRfc4122()]);

        if (false === $value) {
            return null;
        }

        $until = Type::getType(Types::DATETIMETZ_IMMUTABLE)->convertToPHPValue($value, $this->database->connection->getDatabasePlatform());
        \assert($until instanceof \DateTimeImmutable);

        return $until;
    }

    public function purge(\DateTimeImmutable $before): int
    {
        return (int) $this->database->connection->executeStatement(\sprintf('DELETE FROM %s WHERE ends_at < ?', $this->database->table(self::TABLE)), [$before], [Types::DATETIMETZ_IMMUTABLE]);
    }
}
