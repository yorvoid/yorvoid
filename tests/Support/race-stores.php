<?php

declare(strict_types=1);

/*
 * The factory of YorvoID's own stores for the concurrency contracts (Race, StoreConcurrencyTest): each
 * test gets a database of its own, an SQLite file or a PostgreSQL schema named after it, made and
 * migrated by the first process to ask (the test's own) and removed when that process ends.
 *
 * "postgresql losing" is PostgreSQL with stores that lose races (LosingStoreTest): the kit must catch them.
 */

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use YorvoID\Authn\Recovery\RecoveryCodeStore;
use YorvoID\Authn\Session\SessionStore;
use YorvoID\Authn\Setup\SetupStore;
use YorvoID\Authn\Token\OneTimeTokenStore;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\StorageLocation;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Passkeys\CeremonyStore;
use YorvoID\Tests\Support\CheckThenDeleteTokenStore;
use YorvoID\Tests\Support\CheckThenUseRecoveryCodeStore;
use YorvoID\Tests\Support\Stack;
use YorvoID\Throttle\ThrottleStore;

return static function (string $type, string $implementation, string $test): object {
    /** @var array<string, Stack> $stacks */
    static $stacks = [];

    $stack = $stacks[$implementation."\0".$test] ??= (static function () use ($implementation, $test): Stack {
        if (1 !== preg_match('/^[0-9a-f]{12}$/', $test)) {
            throw new InvalidArgumentException(sprintf('Unexpected test "%s".', $test));
        }

        $middleware = new TransactionMiddleware();
        $configuration = new Configuration();
        $configuration->setMiddlewares([$middleware]);

        if ('sqlite' === $implementation) {
            $path = sprintf('%s/yorvoid-race-%s.sqlite', sys_get_temp_dir(), $test);

            if (!file_exists($path)) {
                register_shutdown_function(static fn (): bool => !file_exists($path) || unlink($path));
            }

            $database = new Database(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path], $configuration));
        } else {
            $connection = DriverManager::getConnection(new DsnParser()->parse((string) getenv('YORVOID_TEST_POSTGRES_URL')), $configuration);
            $schema = 'yorvoid_race_'.$test;

            if (false === $connection->fetchOne('SELECT 1 FROM pg_namespace WHERE nspname = ?', [$schema])) {
                $connection->executeStatement(sprintf('CREATE SCHEMA %s', $schema));
                register_shutdown_function(static fn (): int|string => $connection->executeStatement(sprintf('DROP SCHEMA %s CASCADE', $schema)));
            }

            $database = new Database($connection, new StorageLocation(StorageLocation::DEFAULT_PREFIX, $schema));
        }

        return Stack::onDatabase($implementation, $database, $middleware);
    })();
    $losing = 'postgresql losing' === $implementation;
    assert(null !== $stack->database);

    return match ($type) {
        Transactions::class => $stack->transactions,
        OneTimeTokenStore::class => $losing ? new CheckThenDeleteTokenStore($stack->tokens) : $stack->tokens,
        RecoveryCodeStore::class => $losing ? new CheckThenUseRecoveryCodeStore($stack->database, $stack->recoveryCodes) : $stack->recoveryCodes,
        SetupStore::class => $stack->setup,
        CeremonyStore::class => $stack->ceremonies,
        ThrottleStore::class => $stack->throttle,
        PrincipalStore::class => $stack->principals,
        SessionStore::class => $stack->sessions,
        SecurityLogStore::class => $stack->log,
        default => throw new InvalidArgumentException(sprintf('No store for %s.', $type)),
    };
};
