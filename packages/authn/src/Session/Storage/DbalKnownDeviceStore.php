<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session\Storage;

use Doctrine\DBAL\Types\Types;
use YorvoID\Authn\Session\KnownDeviceStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\SqlPlatform;

/**
 * @api
 */
final readonly class DbalKnownDeviceStore implements KnownDeviceStore
{
    public const string TABLE = 'known_device';

    public function __construct(
        private Database $database,
    ) {
    }

    public function recordUse(Realm $realm, PrincipalId $principalId, string $cookieHash, \DateTimeImmutable $at): bool
    {
        $table = $this->database->table(self::TABLE);
        $principal = $principalId->uuid->toRfc4122();

        if (1 === $this->database->connection->executeStatement(\sprintf('UPDATE %s SET last_seen_at = ? WHERE realm = ? AND principal_id = ? AND cookie_hash = ?', $table), [$at, $realm->name, $principal, $cookieHash], [0 => Types::DATETIMETZ_IMMUTABLE])) {
            return true;
        }

        $this->database->connection->executeStatement(match ($this->database->platform) {
            SqlPlatform::PostgreSQL => \sprintf('INSERT INTO %s (realm, principal_id, cookie_hash, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT DO NOTHING', $table),
            SqlPlatform::SQLite => \sprintf('INSERT OR IGNORE INTO %s (realm, principal_id, cookie_hash, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, ?)', $table),
        }, [$realm->name, $principal, $cookieHash, $at, $at], [3 => Types::DATETIMETZ_IMMUTABLE, 4 => Types::DATETIMETZ_IMMUTABLE]);

        return false;
    }

    public function hasAny(Realm $realm, PrincipalId $principalId): bool
    {
        return false !== $this->database->connection->fetchOne(\sprintf('SELECT 1 FROM %s WHERE realm = ? AND principal_id = ?', $this->database->table(self::TABLE)), [$realm->name, $principalId->uuid->toRfc4122()]);
    }

    public function purge(\DateTimeImmutable $unusedSince): int
    {
        return (int) $this->database->connection->executeStatement(\sprintf('DELETE FROM %s WHERE last_seen_at < ?', $this->database->table(self::TABLE)), [$unusedSince], [Types::DATETIMETZ_IMMUTABLE]);
    }
}
