<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use YorvoID\Core\Exception\IntegratorError;

/**
 * The databases YorvoID supports (PLT-024): PostgreSQL first, SQLite for development and tests.
 * MySQL and MariaDB follow; until they pass the storage conformance suite, they're refused rather than
 * half-supported.
 *
 * @api
 */
enum SqlPlatform: string
{
    case PostgreSQL = 'postgresql';
    case SQLite = 'sqlite';

    public static function of(AbstractPlatform $platform): self
    {
        return match (true) {
            $platform instanceof PostgreSQLPlatform => self::PostgreSQL,
            $platform instanceof SQLitePlatform => self::SQLite,
            default => throw new IntegratorError(\sprintf('YorvoID doesn\'t support the database platform %s yet.', $platform::class), 'Use PostgreSQL 16 or later, or SQLite for development and tests. MySQL and MariaDB support is planned.', 'concepts/storage.md#databases'),
        };
    }

    public function supportsSchemas(): bool
    {
        return self::PostgreSQL === $this;
    }
}
