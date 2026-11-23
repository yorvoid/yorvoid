<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use YorvoID\Core\Event\NoEventListeners;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\StorageLocation;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Testing\InMemoryStorage;

/**
 * Runs a test against every database YorvoID supports (PLT-024, PLT-025), each test in a location of its
 * own: a fresh SQLite file, or a fresh PostgreSQL schema, removed afterwards.
 *
 * A test's stack() gets a migrated database without migrating it: a copy of the process's migrated
 * SQLite file, or the process's PostgreSQL schema, reset to what a fresh migration leaves (its tables
 * emptied, the rows the migrations seed copied back from a pristine schema, its sequences as there).
 * A test that changes that schema fails: it's marked FreshDatabase, and gets its own, as do all with
 * YORVOID_TEST_FRESH_DATABASES=1 (CI, each night). Each test has connections of its own, so no session
 * state (settings, advisory locks, temporary tables) outlives it.
 *
 * PostgreSQL comes from YORVOID_TEST_POSTGRES_URL (compose.yaml sets it). Without it, PostgreSQL tests
 * are skipped, unless YORVOID_TEST_REQUIRE_DATABASES lists "postgresql", as CI's does: there, a missing
 * database fails the build rather than silently skipping half the suite.
 */
abstract class DatabaseTestCase extends TestCase
{
    /** @var list<\Closure(): void> */
    private array $cleanups = [];

    /** @var array<string, string> Platform => where this test's database is */
    private array $locations = [];

    /** @var array<string, Stack> One stack per implementation for the test, for the stores' contracts */
    private array $stacks = [];

    /** The process's migrated SQLite file, which tests' files are copies of */
    private static ?string $sqliteTemplate = null;

    /** @var array{work: string, pristine: string, catalog: string}|null The process's PostgreSQL schemas, and the working one's catalog */
    private static ?array $postgres = null;

    /** Whether this test's PostgreSQL location is the process's working schema */
    private bool $reusesPostgres = false;

    /**
     * @return iterable<string, array{string}>
     */
    public static function platforms(): iterable
    {
        yield 'SQLite' => ['sqlite'];
        yield 'PostgreSQL' => ['postgresql'];
    }

    /**
     * Every storage implementation: in memory, and DBAL on each supported database.
     *
     * @return iterable<string, array{string}>
     */
    public static function implementations(): iterable
    {
        yield 'in memory' => ['memory'];

        foreach (self::platforms() as $name => $platform) {
            yield $name => $platform;
        }
    }

    /**
     * YorvoID's stores on one implementation, migrated and ready.
     */
    protected function stack(string $implementation, ?StatementLog $statements = null, ?EventDispatcherInterface $events = null, ?LoggerInterface $logger = null): Stack
    {
        if ('memory' === $implementation) {
            \assert(null === $events && null === $logger, 'The in-memory stack\'s transactions raise no audit alert.');

            $memory = new InMemoryStorage();

            return new Stack($implementation, $memory->transactions, $memory->principals, $memory->identifiers, $memory->log, $memory->sessions, $memory->tokens, $memory->setup, $memory->credentials, $memory->passkeys, $memory->ceremonies, $memory->recoveryCodes, $memory->coolDowns, $memory->throttle, $memory->devices, $memory->policy, $memory->relations, $memory->attributes, decisions: $memory->decisions, versions: $memory->versions);
        }

        $migrated = $this->migrated($implementation);
        $middleware = new TransactionMiddleware();
        $database = new Database($this->connect($implementation, $middleware, $statements), new StorageLocation(StorageLocation::DEFAULT_PREFIX, 'postgresql' === $implementation ? $this->locations[$implementation] : null));

        return Stack::onDatabase($implementation, $database, $middleware, $events ?? new NoEventListeners(), $logger ?? new NullLogger(), migrate: !$migrated);
    }

    /**
     * The test's stack on the implementation: the same one each time, so a contract's stores share it.
     */
    protected function stackOf(string $implementation): Stack
    {
        return $this->stacks[$implementation] ??= $this->stack($implementation);
    }

    /**
     * The transactions the stores of stackOf() take part in, as the store contracts ask.
     */
    protected function transactions(string $implementation): Transactions
    {
        return $this->stackOf($implementation)->transactions;
    }

    protected function tearDown(): void
    {
        $this->stacks = [];

        foreach (array_reverse($this->cleanups) as $cleanup) {
            $cleanup();
        }

        $this->cleanups = [];
        $this->locations = [];

        if ($this->reusesPostgres) {
            $this->reusesPostgres = false;
            $this->checkCatalog();
        }
    }

    /**
     * Gives the test a migrated database, if it has none yet and may share the process's.
     *
     * @return bool Whether it's migrated already
     */
    private function migrated(string $platform): bool
    {
        if (isset($this->locations[$platform]) || $this->wantsFreshDatabase()) {
            return false;
        }

        if ('sqlite' === $platform) {
            $path = $this->sqliteFile();
            copy(self::$sqliteTemplate ??= self::sqliteTemplate(), $path);
            $this->locations['sqlite'] = $path;

            return true;
        }

        $url = self::postgresUrl();

        if (null === $url) {
            return false;
        }

        $schemas = self::$postgres ??= self::postgresSchemas($url);
        self::reset($url, $schemas);
        $this->locations['postgresql'] = $schemas['work'];
        $this->reusesPostgres = true;

        return true;
    }

    private function wantsFreshDatabase(): bool
    {
        if ('1' === getenv('YORVOID_TEST_FRESH_DATABASES')) {
            return true;
        }

        $class = new \ReflectionClass($this);

        if ([] !== $class->getAttributes(FreshDatabase::class)) {
            return true;
        }

        return $class->hasMethod($this->name()) && [] !== $class->getMethod($this->name())->getAttributes(FreshDatabase::class);
    }

    private static function sqliteTemplate(): string
    {
        $path = \sprintf('%s/yorvoid-template-%d-%s.sqlite', sys_get_temp_dir(), getmypid(), bin2hex(random_bytes(4)));
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        Stack::migrate(new Database($connection, new StorageLocation(StorageLocation::DEFAULT_PREFIX)));
        $connection->close();
        register_shutdown_function(static function () use ($path): void {
            if (file_exists($path)) {
                unlink($path);
            }
        });

        return $path;
    }

    /**
     * Two schemas for the process, migrated: one it works in, one that stays as the migrations left it.
     *
     * @return array{work: string, pristine: string, catalog: string}
     */
    private static function postgresSchemas(string $url): array
    {
        $suffix = getmypid().'_'.bin2hex(random_bytes(3));
        $schemas = ['work' => 'yorvoid_test_w'.$suffix, 'pristine' => 'yorvoid_test_p'.$suffix];
        $connection = DriverManager::getConnection(new DsnParser()->parse($url));

        foreach ($schemas as $schema) {
            Stack::migrate(new Database($connection, new StorageLocation(StorageLocation::DEFAULT_PREFIX, $schema)));
        }

        $catalog = self::catalog($connection, $schemas['work']);
        $connection->close();
        register_shutdown_function(static function () use ($url, $schemas): void {
            self::dropSchemas($url, $schemas);
        });

        return [...$schemas, 'catalog' => $catalog];
    }

    /**
     * @param array{work: string, pristine: string} $schemas
     */
    private static function dropSchemas(string $url, array $schemas): void
    {
        $connection = DriverManager::getConnection(new DsnParser()->parse($url));

        foreach ([$schemas['work'], $schemas['pristine']] as $schema) {
            $connection->executeStatement(\sprintf('DROP SCHEMA IF EXISTS "%s" CASCADE', $schema));
        }

        $connection->close();
    }

    /**
     * Puts the working schema back as a fresh migration leaves it: every table emptied, the rows the
     * migrations seeded copied back from the pristine schema, and each sequence where it is there.
     *
     * @param array{work: string, pristine: string, catalog: string} $schemas
     */
    private static function reset(string $url, array $schemas): void
    {
        $connection = DriverManager::getConnection(new DsnParser()->parse($url));
        $tables = self::names($connection->fetchFirstColumn('SELECT tablename FROM pg_tables WHERE schemaname = ? ORDER BY tablename', [$schemas['work']]));
        $quoted = static fn (string $schema, string $name): string => \sprintf('"%s"."%s"', $schema, $name);

        $connection->transactional(static function (Connection $connection) use ($schemas, $tables, $quoted): void {
            // In any order: no foreign key is checked (replica). DELETE, since TRUNCATE takes six times as long.
            $connection->executeStatement('SET LOCAL session_replication_role = replica');

            foreach ($tables as $table) {
                $connection->executeStatement(\sprintf('DELETE FROM %s', $quoted($schemas['work'], $table)));
                $connection->executeStatement(\sprintf('INSERT INTO %s SELECT * FROM %s', $quoted($schemas['work'], $table), $quoted($schemas['pristine'], $table)));
            }

            foreach (self::names($connection->fetchFirstColumn('SELECT sequencename FROM pg_sequences WHERE schemaname = ?', [$schemas['work']])) as $sequence) {
                $last = $connection->fetchOne('SELECT last_value FROM pg_sequences WHERE schemaname = ? AND sequencename = ?', [$schemas['pristine'], $sequence]);

                if (null === $last || false === $last) {
                    $connection->executeStatement(\sprintf('ALTER SEQUENCE %s RESTART', $quoted($schemas['work'], $sequence)));
                } else {
                    $connection->executeStatement('SELECT setval(?::regclass, ?, true)', [$quoted($schemas['work'], $sequence), $last]);
                }
            }
        });

        $connection->close();
    }

    /**
     * Resets the process's PostgreSQL schema now, as the next test's stack() would: for the test of the
     * reset itself.
     */
    protected function resetSharedDatabase(): void
    {
        $url = self::postgresUrl();

        if (!$this->reusesPostgres || null === self::$postgres || null === $url) {
            self::fail('This test doesn\'t use the process\'s PostgreSQL schema.');
        }

        self::reset($url, self::$postgres);
    }

    /**
     * Fails a test that changed the process's schema, which the next tests would inherit, and drops it:
     * the next test migrates a new one.
     */
    private function checkCatalog(): void
    {
        $schemas = self::$postgres;
        $url = self::postgresUrl();

        if (null === $schemas || null === $url) {
            return;
        }

        $connection = DriverManager::getConnection(new DsnParser()->parse($url));
        $catalog = self::catalog($connection, $schemas['work']);
        $connection->close();

        if ($catalog !== $schemas['catalog']) {
            self::$postgres = null;
            self::dropSchemas($url, $schemas);
            self::fail('The test changed the shared database\'s schema: mark it #[FreshDatabase], so it gets its own.');
        }
    }

    /**
     * What the schema is made of: tables, columns, indexes, constraints, triggers and functions with their
     * definitions, row security policies, enum, domain and composite types, and sequences.
     */
    private static function catalog(Connection $connection, string $schema): string
    {
        $catalog = $connection->fetchOne(<<<'SQL'
            SELECT md5(coalesce(string_agg(part, ',' ORDER BY part), '')) FROM (
                SELECT 'c:' || table_name || '.' || column_name || ':' || data_type || ':' || coalesce(column_default, '') || ':' || is_nullable FROM information_schema.columns WHERE table_schema = :schema
                UNION ALL SELECT 'i:' || indexname || ':' || indexdef FROM pg_indexes WHERE schemaname = :schema
                UNION ALL SELECT 'k:' || conname || ':' || pg_get_constraintdef(c.oid) FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace WHERE n.nspname = :schema
                UNION ALL SELECT 't:' || pg_get_triggerdef(t.oid) FROM pg_trigger t JOIN pg_class r ON r.oid = t.tgrelid JOIN pg_namespace n ON n.oid = r.relnamespace WHERE n.nspname = :schema AND NOT t.tgisinternal
                UNION ALL SELECT 'f:' || proname || ':' || md5(pg_get_functiondef(p.oid)) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = :schema AND p.prokind IN ('f', 'p')
                UNION ALL SELECT 'p:' || r.relname || '.' || pol.polname || ':' || pol.polcmd::text || ':' || coalesce(pg_get_expr(pol.polqual, pol.polrelid), '') || ':' || coalesce(pg_get_expr(pol.polwithcheck, pol.polrelid), '') FROM pg_policy pol JOIN pg_class r ON r.oid = pol.polrelid JOIN pg_namespace n ON n.oid = r.relnamespace WHERE n.nspname = :schema
                UNION ALL SELECT 'y:' || ty.typname || ':' || coalesce((SELECT string_agg(e.enumlabel, '|' ORDER BY e.enumsortorder) FROM pg_enum e WHERE e.enumtypid = ty.oid), '') FROM pg_type ty JOIN pg_namespace n ON n.oid = ty.typnamespace WHERE n.nspname = :schema AND ty.typtype IN ('e', 'd', 'c') AND NOT EXISTS (SELECT 1 FROM pg_class c WHERE c.reltype = ty.oid)
                UNION ALL SELECT 's:' || sequencename FROM pg_sequences WHERE schemaname = :schema
            ) parts(part)
            SQL, ['schema' => $schema]);

        return \is_string($catalog) ? $catalog : throw new \UnexpectedValueException('The catalog has no hash.');
    }

    /**
     * @param list<mixed> $rows
     *
     * @return list<string>
     */
    private static function names(array $rows): array
    {
        return array_map(static fn (mixed $name): string => \is_string($name) ? $name : throw new \UnexpectedValueException('A name isn\'t a string.'), $rows);
    }

    private static function postgresUrl(): ?string
    {
        $url = getenv('YORVOID_TEST_POSTGRES_URL');

        return false === $url || '' === $url ? null : $url;
    }

    /**
     * YorvoID's database for this test: a new connection to the test's own location.
     */
    protected function database(string $platform, string $prefix = StorageLocation::DEFAULT_PREFIX): Database
    {
        $connection = $this->connect($platform);

        return new Database($connection, new StorageLocation($prefix, 'postgresql' === $platform ? $this->locations[$platform] : null));
    }

    /**
     * YorvoID's database in a PostgreSQL schema of the test's choosing, removed afterwards.
     */
    protected function postgresDatabase(string $schema, string $prefix): Database
    {
        $connection = $this->connect('postgresql');
        $this->cleanups[] = static function () use ($connection, $schema): void {
            $connection->executeStatement(\sprintf('DROP SCHEMA IF EXISTS "%s" CASCADE', $schema));
        };

        return new Database($connection, new StorageLocation($prefix, $schema));
    }

    /**
     * Another connection to the same database, as a second process would have.
     */
    protected function connect(string $platform, ?TransactionMiddleware $middleware = null, ?StatementLog $statements = null): Connection
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares(array_values(array_filter([$middleware, null === $statements ? null : new LoggingMiddleware($statements)])));

        $connection = match ($platform) {
            'sqlite' => DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->locations['sqlite'] ??= $this->sqliteFile()], $configuration),
            'postgresql' => $this->postgres($configuration),
            default => throw new \LogicException(\sprintf('Unknown platform "%s".', $platform)),
        };

        $this->cleanups[] = $connection->close(...);

        return $connection;
    }

    private function sqliteFile(): string
    {
        $path = \sprintf('%s/yorvoid-test-%s.sqlite', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        $this->cleanups[] = static function () use ($path): void {
            if (file_exists($path)) {
                unlink($path);
            }
        };

        return $path;
    }

    private function postgres(Configuration $configuration): Connection
    {
        $url = getenv('YORVOID_TEST_POSTGRES_URL');

        if (false === $url || '' === $url) {
            if (\in_array('postgresql', explode(',', (string) getenv('YORVOID_TEST_REQUIRE_DATABASES')), true)) {
                self::fail('YORVOID_TEST_POSTGRES_URL is required here, but not set.');
            }

            self::markTestSkipped('No PostgreSQL to test against: set YORVOID_TEST_POSTGRES_URL.');
        }

        $connection = DriverManager::getConnection(new DsnParser()->parse($url), $configuration);

        if (!isset($this->locations['postgresql'])) {
            $schema = 'yorvoid_test_'.bin2hex(random_bytes(6));
            $this->locations['postgresql'] = $schema;
            $this->cleanups[] = static function () use ($url, $schema): void {
                $admin = DriverManager::getConnection(new DsnParser()->parse($url));
                $admin->executeStatement(\sprintf('DROP SCHEMA IF EXISTS "%s" CASCADE', $schema));
                $admin->close();
            };
        }

        return $connection;
    }
}
