<?php

declare(strict_types=1);

/*
 * Before a run (tools/bench/ci.sh): PostgreSQL writes what an earlier run left, so that a run doesn't
 * pay for the one before it, which would make whichever runs second look slower.
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$url = getenv('YORVOID_TEST_POSTGRES_URL');

if (false === $url || '' === $url) {
    throw new RuntimeException('The benchmarks run on PostgreSQL: set YORVOID_TEST_POSTGRES_URL.');
}

$connection = DriverManager::getConnection(new DsnParser()->parse($url));
$connection->executeStatement('CHECKPOINT');
$connection->close();
