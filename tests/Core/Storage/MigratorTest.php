<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Storage;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\MigrationContext;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Tests\Core\Storage\Fixtures\TestMigration;
use YorvoID\Tests\Support\DatabaseTestCase;

final class MigratorTest extends DatabaseTestCase
{
    private const string FIRST = '20261027180000_test_widget';
    private const string SECOND = '20261027181000_test_widget_name';

    #[DataProvider('platforms')]
    public function testRunsPendingMigrationsInOrderAndRecordsThem(string $platform): void
    {
        $database = $this->database($platform);
        $migrator = new Migrator($database, [self::addName(), self::createWidget()], new MockClock('2026-10-27 18:30:00 UTC'));

        self::assertSame([self::FIRST, self::SECOND], array_map(static fn ($migration): string => $migration->version(), $migrator->pending()));
        self::assertSame([self::FIRST, self::SECOND], $migrator->migrate());

        $database->connection->insert($database->table('widget'), ['id' => 1, 'name' => 'gear']);
        self::assertSame('gear', $database->connection->fetchOne(\sprintf('SELECT name FROM %s', $database->table('widget'))));

        $status = $migrator->status();
        self::assertCount(2, $status);
        self::assertSame(self::FIRST, $status[0]->version);
        self::assertSame('Create widgets', $status[0]->description);
        self::assertSame(LockProfile::Online, $status[0]->lockProfile);
        self::assertTrue($status[0]->isExecuted());
        self::assertFalse($status[0]->isUnknown());
        self::assertEquals(new \DateTimeImmutable('2026-10-27 18:30:00 UTC'), $status[0]->executedAt);
        self::assertSame([], $migrator->pending());
    }

    #[DataProvider('platforms')]
    public function testRunningAgainDoesNothing(string $platform): void
    {
        $database = $this->database($platform);
        new Migrator($database, [self::createWidget()])->migrate();

        $migrator = new Migrator($database, [self::createWidget(), self::addName()]);
        self::assertSame([self::SECOND], $migrator->migrate());
        self::assertSame([], $migrator->migrate());
    }

    #[DataProvider('platforms')]
    public function testStatusShowsPendingAndUnknownMigrations(string $platform): void
    {
        $database = $this->database($platform);
        new Migrator($database, [self::createWidget()])->migrate();
        $database->connection->executeStatement(\sprintf("INSERT INTO %s (version, description, executed_at) VALUES ('20991231235959_from_the_future', 'Newer', CURRENT_TIMESTAMP), ('20261027180500_in_between', NULL, CURRENT_TIMESTAMP)", $database->table(Migrator::HISTORY_TABLE)));

        $status = new Migrator($database, [self::createWidget(), self::addName()])->status();

        // In order of versions, wherever the unknown ones fall.
        self::assertSame([self::FIRST, '20261027180500_in_between', self::SECOND, '20991231235959_from_the_future'], array_map(static fn ($state): string => $state->version, $status));
        self::assertTrue($status[0]->isExecuted());
        self::assertTrue($status[1]->isUnknown());
        self::assertNull($status[1]->description);
        self::assertFalse($status[2]->isExecuted());
        self::assertFalse($status[2]->isUnknown());
        self::assertTrue($status[3]->isExecuted());
        self::assertTrue($status[3]->isUnknown());
        self::assertSame('Newer', $status[3]->description);
        self::assertNull($status[3]->lockProfile);
    }

    #[DataProvider('platforms')]
    public function testStatusOfAFreshDatabase(string $platform): void
    {
        $status = new Migrator($this->database($platform), [self::createWidget()])->status();

        self::assertCount(1, $status);
        self::assertFalse($status[0]->isExecuted());
    }

    #[DataProvider('platforms')]
    public function testTheSqlForAnAdministratorIsWhatMigrateRuns(string $platform): void
    {
        $database = $this->database($platform);
        $migrator = new Migrator($database, [self::createWidget(), self::addName()]);
        $statements = $migrator->sql();

        foreach ($statements as $statement) {
            $database->connection->executeStatement($statement);
        }

        self::assertSame([], $migrator->pending());
        self::assertSame([], $migrator->sql());
        self::assertSame([], $migrator->migrate());
        self::assertEquals(0, $database->connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $database->table('widget'))));
    }

    #[DataProvider('platforms')]
    public function testAFailedMigrationLeavesNothingBehind(string $platform): void
    {
        $database = $this->database($platform);
        $failing = new TestMigration(self::SECOND, static function (MigrationContext $m): void {
            $m->createTable('half_done', static function (TableEditor $t): void {
                $t->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->create());
            });
            $m->execute('THIS IS NOT SQL');
        });

        try {
            new Migrator($database, [self::createWidget(), $failing])->migrate();
            self::fail('The failing migration ran.');
        } catch (\Doctrine\DBAL\Exception) {
        }

        $migrator = new Migrator($database, [self::createWidget(), $failing]);
        self::assertSame([self::SECOND], array_map(static fn ($migration): string => $migration->version(), $migrator->pending()));
        self::assertFalse($this->tableExists($database, 'half_done'));
        self::assertTrue($this->tableExists($database, 'widget'));
    }

    #[DataProvider('platforms')]
    public function testRefusesBlockingMigrationsUnlessAllowed(string $platform): void
    {
        $database = $this->database($platform);
        $blocking = new TestMigration(self::SECOND, static fn (MigrationContext $m) => $m->execute(\sprintf('CREATE INDEX %s ON %s (id)', $m->name('widget_by_id'), $m->table('widget'))), LockProfile::Blocking);

        try {
            new Migrator($database, [self::createWidget(), $blocking])->migrate();
            self::fail('The blocking migration ran.');
        } catch (IntegratorError $error) {
            self::assertStringContainsString('blocks tables while it runs', $error->problem);
        }

        // Nothing ran, not even the online migration before it: the plan is checked first.
        self::assertFalse($this->tableExists($database, 'widget'));
        self::assertSame([self::FIRST, self::SECOND], new Migrator($database, [self::createWidget(), $blocking])->migrate(allowBlocking: true));
    }

    #[DataProvider('platforms')]
    public function testRefusesToRunInsideATransaction(string $platform): void
    {
        $database = $this->database($platform);
        $database->connection->beginTransaction();

        try {
            $this->expectException(IntegratorError::class);
            $this->expectExceptionMessage('inside a transaction');

            new Migrator($database, [self::createWidget()])->migrate();
        } finally {
            $database->connection->rollBack();
        }
    }

    public function testTablesStayInTheirSchemaAndPrefix(): void
    {
        $database = $this->database('postgresql', 'auth_');
        $schema = $database->location->schema;
        $database->connection->executeStatement(\sprintf('CREATE SCHEMA "%s"', $schema));
        $database->connection->executeStatement(\sprintf('CREATE TABLE "%s".app_user (id INT)', $schema));

        new Migrator($database, [self::createWidget()])->migrate();

        $tables = $database->connection->fetchFirstColumn('SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name', [$schema]);
        self::assertSame(['app_user', 'auth_migration', 'auth_widget'], $tables);

        foreach ($tables as $table) {
            self::assertSame('app_user' !== $table, $database->location->owns($schema.'.'.$table), $table);
        }

        // As the docs say: the application's schema tools, given the filter, see only its own table.
        $application = $this->connect('postgresql');
        $location = $database->location;
        $application->getConfiguration()->setSchemaAssetsFilter(static fn (string $name): bool => !$location->owns($name));
        $application->executeStatement(\sprintf('SET search_path TO "%s"', $schema));
        // Tables of the search path come unqualified; other schemas' (e.g. other tests') qualified.
        $inSchema = array_values(array_filter($application->createSchemaManager()->listTableNames(), static fn (string $table): bool => !str_contains($table, '.')));
        self::assertSame(['app_user'], $inSchema);
    }

    public function testCreatesItsSchemaWhenMissing(): void
    {
        $database = $this->database('postgresql');
        new Migrator($database, [self::createWidget()])->migrate();

        self::assertEquals(1, $database->connection->fetchOne('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?', [$database->location->schema]));
    }

    public function testOneProcessAtATime(): void
    {
        $database = $this->database('postgresql');
        $other = new Database($this->connect('postgresql'), $database->location);
        $outcome = null;

        // While the first process is inside its migration, a second one for the same location waits,
        // and gives up after its timeout.
        $first = new TestMigration(self::FIRST, static function () use ($other, &$outcome): void {
            try {
                new Migrator($other, [], lockTimeout: Duration::seconds(1))->migrate();
                $outcome = 'ran';
            } catch (StorageException $exception) {
                $outcome = $exception->getMessage();
            }
        });

        $start = microtime(true);
        new Migrator($database, [$first])->migrate();
        $waited = microtime(true) - $start;

        self::assertIsString($outcome);
        self::assertStringContainsString('Another process is running YorvoID\'s migrations', $outcome);
        self::assertStringContainsString('after waiting 1s', $outcome);
        self::assertGreaterThanOrEqual(1.0, $waited, 'The second process gave up before its timeout.');
        self::assertLessThan(3.0, $waited, 'The second process waited much longer than its timeout.');

        // Afterwards, the lock is free again.
        self::assertSame([], new Migrator($other, [$first], lockTimeout: Duration::seconds(1))->migrate());
    }

    public function testOtherLocationsDontWait(): void
    {
        $database = $this->database('postgresql');
        $elsewhere = $this->database('postgresql', 'other_');
        $ran = null;

        $first = new TestMigration(self::FIRST, static function () use ($elsewhere, &$ran): void {
            $ran = new Migrator($elsewhere, [self::createWidget()], lockTimeout: Duration::seconds(1))->migrate();
        });

        new Migrator($database, [$first])->migrate();

        self::assertSame([self::FIRST], $ran);
    }

    public function testLocationsWhoseNamesRunTogetherDontWaitEither(): void
    {
        // "t…" + "b_" and "t…b" + "_" read the same when schema and prefix are run together.
        $base = 'yorvoid_test_'.bin2hex(random_bytes(4));
        $database = $this->postgresDatabase($base, 'b_');
        $elsewhere = $this->postgresDatabase($base.'b', '_');
        $ran = null;

        $first = new TestMigration(self::FIRST, static function () use ($elsewhere, &$ran): void {
            $ran = new Migrator($elsewhere, [self::createWidget()], lockTimeout: Duration::seconds(1))->migrate();
        });

        new Migrator($database, [$first])->migrate();

        self::assertSame([self::FIRST], $ran);
    }

    public function testTheSamePrefixInAnotherSchemaDoesntWait(): void
    {
        $base = 'yorvoid_test_'.bin2hex(random_bytes(4));
        $database = $this->postgresDatabase($base.'_a', 'yorvoid_');
        $elsewhere = $this->postgresDatabase($base.'_b', 'yorvoid_');
        $ran = null;

        $first = new TestMigration(self::FIRST, static function () use ($elsewhere, &$ran): void {
            $ran = new Migrator($elsewhere, [self::createWidget()], lockTimeout: Duration::seconds(1))->migrate();
        });

        new Migrator($database, [$first])->migrate();

        self::assertSame([self::FIRST], $ran);
    }

    #[DataProvider('platforms')]
    public function testTheHistoryRecordsEachVersionOnce(string $platform): void
    {
        $database = $this->database($platform);
        new Migrator($database, [self::createWidget()])->migrate();

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $database->connection->insert($database->table(Migrator::HISTORY_TABLE), ['version' => self::FIRST, 'description' => 'Again', 'executed_at' => '2026-10-27 18:00:00+00']);
    }

    #[DataProvider('platforms')]
    public function testTheLongestVersionAndDescriptionFit(string $platform): void
    {
        $database = $this->database($platform);
        $version = '20261027180000_'.str_repeat('v', Migrator::MAX_VERSION_LENGTH - 15);
        $description = str_repeat('é', Migrator::MAX_DESCRIPTION_LENGTH);

        new Migrator($database, [new TestMigration($version, static fn () => null, description: $description)])->migrate();

        $status = new Migrator($database, [])->status();
        self::assertSame($version, $status[0]->version);
        self::assertSame($description, $status[0]->description);
    }

    public function testRefusesALongerVersion(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('at most 191 characters');

        new Migrator($this->database('sqlite'), [new TestMigration('20261027180000_'.str_repeat('v', Migrator::MAX_VERSION_LENGTH - 14), static fn () => null)]);
    }

    public function testRefusesALongerDescription(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('at most 255 characters');

        new Migrator($this->database('sqlite'), [new TestMigration(self::FIRST, static fn () => null, description: str_repeat('é', Migrator::MAX_DESCRIPTION_LENGTH + 1))]);
    }

    public function testTheLockIsReleasedWhenAMigrationFails(): void
    {
        $database = $this->database('postgresql');

        try {
            new Migrator($database, [new TestMigration(self::FIRST, static fn (MigrationContext $m) => $m->execute('THIS IS NOT SQL'))])->migrate();
        } catch (\Doctrine\DBAL\Exception) {
        }

        $other = new Database($this->connect('postgresql'), $database->location);
        self::assertSame([self::FIRST], new Migrator($other, [self::createWidget()], lockTimeout: Duration::seconds(1))->migrate());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidVersions(): iterable
    {
        yield 'no description' => ['20261027180000'];
        yield 'short time' => ['202610271800_widget'];
        yield 'uppercase' => ['20261027180000_Widget'];
        yield 'hyphen' => ['20261027180000_widget-name'];
        yield 'trailing newline' => ["20261027180000_widget\n"];
    }

    #[DataProvider('invalidVersions')]
    public function testRefusesInvalidVersions(string $version): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('is invalid');

        new Migrator($this->database('sqlite'), [new TestMigration($version, static fn () => null)]);
    }

    public function testRefusesTwoMigrationsWithOneVersion(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('Two migrations have the version');

        new Migrator($this->database('sqlite'), [self::createWidget(), self::createWidget()]);
    }

    private static function createWidget(): TestMigration
    {
        return new TestMigration(self::FIRST, static function (MigrationContext $m): void {
            $m->createTable('widget', static function (TableEditor $t): void {
                $t->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->create());
                $t->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
            });
        }, description: 'Create widgets');
    }

    private static function addName(): TestMigration
    {
        return new TestMigration(self::SECOND, static fn (MigrationContext $m) => $m->execute(\sprintf('ALTER TABLE %s ADD COLUMN name VARCHAR(50)', $m->table('widget'))), description: 'Name widgets');
    }

    private function tableExists(Database $database, string $name): bool
    {
        $table = $database->location->name($name);

        return false !== ('sqlite' === $database->platform->value
            ? $database->connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])
            : $database->connection->fetchOne('SELECT 1 FROM pg_catalog.pg_tables WHERE schemaname = ? AND tablename = ?', [$database->location->schema, $table]));
    }
}
