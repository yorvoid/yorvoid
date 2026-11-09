<?php

declare(strict_types=1);

namespace YorvoID\Bench;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\StorageLocation;
use YorvoID\Tests\Support\Stack;

/**
 * YorvoID's stores on PostgreSQL, the database production runs on, in a schema of the benchmark's own,
 * migrated: from YORVOID_TEST_POSTGRES_URL, as the tests have it (compose.yaml, CI).
 */
final class Postgres
{
    public static function stack(string $name): Stack
    {
        $url = getenv('YORVOID_TEST_POSTGRES_URL');

        if (false === $url || '' === $url) {
            throw new \RuntimeException('The benchmarks run on PostgreSQL: set YORVOID_TEST_POSTGRES_URL.');
        }

        $schema = 'yorvoid_bench_'.$name;
        $admin = DriverManager::getConnection(new DsnParser()->parse($url));
        $admin->executeStatement(\sprintf('DROP SCHEMA IF EXISTS "%s" CASCADE', $schema));
        $admin->close();
        $middleware = new TransactionMiddleware();
        $configuration = new Configuration();
        $configuration->setMiddlewares([$middleware]);
        $database = new Database(DriverManager::getConnection(new DsnParser()->parse($url), $configuration), new StorageLocation(StorageLocation::DEFAULT_PREFIX, $schema));

        return Stack::onDatabase('postgresql', $database, $middleware);
    }
}
