<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Storage;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\SqlPlatform;
use YorvoID\Core\Storage\StorageLocation;

final class DatabaseTest extends TestCase
{
    public function testQuotesAndQualifiesTables(): void
    {
        $sqlite = new Database(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        self::assertSame(SqlPlatform::SQLite, $sqlite->platform);
        self::assertSame('"yorvoid_principal"', $sqlite->table('principal'));

        $postgres = new Database(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '18']), new StorageLocation('yorvoid_', 'quire'));
        self::assertSame(SqlPlatform::PostgreSQL, $postgres->platform);
        self::assertSame('"quire"."yorvoid_principal"', $postgres->table('principal'));
        self::assertSame('"yorvoid_principal"', new Database($postgres->connection)->table('principal'));
    }

    public function testRefusesASchemaWhereThereAreNone(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('has no schemas');

        new Database(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), new StorageLocation('yorvoid_', 'quire'));
    }

    public function testKnowsWhichPlatformsAreSupported(): void
    {
        self::assertSame(SqlPlatform::PostgreSQL, SqlPlatform::of(new PostgreSQLPlatform()));
        self::assertSame(SqlPlatform::SQLite, SqlPlatform::of(new SQLitePlatform()));
        self::assertTrue(SqlPlatform::PostgreSQL->supportsSchemas());
        self::assertFalse(SqlPlatform::SQLite->supportsSchemas());
    }

    public function testRefusesPlatformsThatArentSupportedYet(): void
    {
        foreach ([new MySQL84Platform(), new MariaDBPlatform()] as $platform) {
            try {
                SqlPlatform::of($platform);
                self::fail(\sprintf('%s was accepted.', $platform::class));
            } catch (IntegratorError $error) {
                self::assertStringContainsString('doesn\'t support the database platform', $error->problem);
            }
        }
    }
}
