<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use YorvoID\Authn\Credential\Storage\DbalCredentialStore;
use YorvoID\Authn\Credential\Storage\InMemoryCredentialStore;
use YorvoID\Authn\Migrations\AuthnMigrations;
use YorvoID\Authn\Recovery\Storage\DbalRecoveryCodeStore;
use YorvoID\Authn\Recovery\Storage\InMemoryRecoveryCodeStore;
use YorvoID\Authn\Session\Storage\DbalKnownDeviceStore;
use YorvoID\Authn\Session\Storage\DbalSessionStore;
use YorvoID\Authn\Session\Storage\InMemoryKnownDeviceStore;
use YorvoID\Authn\Session\Storage\InMemorySessionStore;
use YorvoID\Authn\Setup\Storage\DbalSetupStore;
use YorvoID\Authn\Setup\Storage\InMemorySetupStore;
use YorvoID\Authn\StepUp\Storage\DbalCoolDownStore;
use YorvoID\Authn\StepUp\Storage\InMemoryCoolDownStore;
use YorvoID\Authn\Token\Storage\DbalOneTimeTokenStore;
use YorvoID\Authn\Token\Storage\InMemoryOneTimeTokenStore;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Migrations\CoreMigrations;
use YorvoID\Core\Principal\Storage\DbalIdentifierStore;
use YorvoID\Core\Principal\Storage\DbalPrincipalStore;
use YorvoID\Core\Principal\Storage\InMemoryIdentifierStore;
use YorvoID\Core\Principal\Storage\InMemoryPrincipalStore;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Core\Storage\StorageLocation;
use YorvoID\Passkeys\Migrations\PasskeysMigrations;
use YorvoID\Passkeys\Storage\DbalCeremonyStore;
use YorvoID\Passkeys\Storage\DbalPasskeyStore;
use YorvoID\Passkeys\Storage\InMemoryCeremonyStore;
use YorvoID\Passkeys\Storage\InMemoryPasskeyStore;
use YorvoID\Throttle\Migrations\ThrottleMigrations;
use YorvoID\Throttle\Storage\DbalThrottleStore;
use YorvoID\Throttle\Storage\InMemoryThrottleStore;

/**
 * Runs a test against every database YorvoID supports (PLT-024, PLT-025), each test in a location of its
 * own: a fresh SQLite file, or a fresh PostgreSQL schema, removed afterwards.
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
    protected function stack(string $implementation): Stack
    {
        if ('memory' === $implementation) {
            $transactions = new InMemoryTransactions();
            $principals = new InMemoryPrincipalStore();
            $identifiers = new InMemoryIdentifierStore();
            $log = new InMemorySecurityLogStore();
            $sessions = new InMemorySessionStore();
            $tokens = new InMemoryOneTimeTokenStore();
            $setup = new InMemorySetupStore();
            $credentials = new InMemoryCredentialStore();
            $passkeys = new InMemoryPasskeyStore();
            $ceremonies = new InMemoryCeremonyStore();
            $recoveryCodes = new InMemoryRecoveryCodeStore();
            $coolDowns = new InMemoryCoolDownStore();
            $devices = new InMemoryKnownDeviceStore();
            $transactions->register($principals);
            $transactions->register($identifiers);
            $transactions->register($log);
            $transactions->register($sessions);
            $transactions->register($tokens);
            $transactions->register($setup);
            $transactions->register($credentials);
            $transactions->register($passkeys);
            $transactions->register($ceremonies);
            $transactions->register($recoveryCodes);
            $transactions->register($coolDowns);
            $transactions->register($devices);

            return new Stack($implementation, $transactions, $principals, $identifiers, $log, $sessions, $tokens, $setup, $credentials, $passkeys, $ceremonies, $recoveryCodes, $coolDowns, new InMemoryThrottleStore(), $devices);
        }

        $middleware = new TransactionMiddleware();
        $database = new Database($this->connect($implementation, $middleware), new StorageLocation(StorageLocation::DEFAULT_PREFIX, 'postgresql' === $implementation ? $this->locations[$implementation] : null));
        new Migrator($database, [...CoreMigrations::all(), ...AuthnMigrations::all(), ...PasskeysMigrations::all(), ...ThrottleMigrations::all()])->migrate();

        return new Stack($implementation, new DbalTransactions($database->connection, $middleware), new DbalPrincipalStore($database), new DbalIdentifierStore($database), new DbalSecurityLogStore($database), new DbalSessionStore($database), new DbalOneTimeTokenStore($database), new DbalSetupStore($database), new DbalCredentialStore($database), new DbalPasskeyStore($database), new DbalCeremonyStore($database), new DbalRecoveryCodeStore($database), new DbalCoolDownStore($database), new DbalThrottleStore($database), new DbalKnownDeviceStore($database), $database);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanups) as $cleanup) {
            $cleanup();
        }

        $this->cleanups = [];
        $this->locations = [];
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
    protected function connect(string $platform, ?TransactionMiddleware $middleware = null): Connection
    {
        $configuration = new Configuration();

        if (null !== $middleware) {
            $configuration->setMiddlewares([$middleware]);
        }

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
