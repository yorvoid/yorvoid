<?php

declare(strict_types=1);

namespace YorvoID\Tests\Migrations;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Authorization\Storage\DbalVersionStore;
use YorvoID\Core\Migrations\CoreMigrations;
use YorvoID\Core\Migrations\CreateVersions;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Policy\Migrations\DropPolicyVersion;
use YorvoID\Policy\Migrations\PolicyMigrations;
use YorvoID\Relations\Migrations\DropRelationVersion;
use YorvoID\Relations\Migrations\RelationsMigrations;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\FreshDatabase;

/**
 * The versions moving to core's table: no realm's version goes back, since a cache keyed on versions
 * would serve what it kept for a version the realm reaches again (D-44).
 */
final class VersionsMigrationTest extends DatabaseTestCase
{
    #[DataProvider('platforms')]
    #[FreshDatabase]
    public function testNoVersionIsLowerAfterTheMigration(string $platform): void
    {
        $database = $this->database($platform, 'moved_');
        $all = [...CoreMigrations::all(), ...PolicyMigrations::all(), ...RelationsMigrations::all()];
        $moving = [CreateVersions::class, DropPolicyVersion::class, DropRelationVersion::class];
        new Migrator($database, array_values(array_filter($all, static fn (Migration $migration): bool => !\in_array($migration::class, $moving, true))))->migrate();
        $database->connection->insert($database->table('policy_version'), ['realm' => 'acme', 'version' => 41]);
        $database->connection->insert($database->table('policy_version'), ['realm' => 'beta', 'version' => 3]);
        $database->connection->insert($database->table('relation_version'), ['realm' => 'acme', 'version' => 17]);

        $exists = static fn (): bool => 1 === \count($database->connection->fetchAllAssociative(\sprintf('SELECT version FROM %s WHERE realm = ? AND version = ?', $database->table('policy_version')), ['beta', 3]));
        self::assertTrue($exists(), 'Before, the old table holds it.');

        new Migrator($database, $all)->migrate();
        $versions = new DbalVersionStore($database);

        self::assertSame(['policy' => 41, 'relations' => 17], $versions->versions(new Realm('acme'), ['policy', 'relations']));
        self::assertSame(['policy' => 3, 'relations' => 0], $versions->versions(new Realm('beta'), ['policy', 'relations']));
        try {
            $exists();
            self::fail('The old table is still there.');
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            // Gone.
        }
    }
}
