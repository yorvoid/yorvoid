<?php

declare(strict_types=1);

/*
 * Before measuring (tools/bench/ci.sh): fails unless PostgreSQL's WAL settings are as the mode needs,
 * off for a pull request and the A/A record (compose.bench-gate.yaml), on for main.
 *
 *   php tools/bench/durability.php on|off
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use YorvoID\Bench\Durability;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$expected = $argv[1] ?? null;

if ('on' !== $expected && 'off' !== $expected) {
    fwrite(\STDERR, "Usage: php tools/bench/durability.php on|off\n");

    exit(2);
}

$url = getenv('YORVOID_TEST_POSTGRES_URL');

if (false === $url || '' === $url) {
    throw new RuntimeException('The benchmarks run on PostgreSQL: set YORVOID_TEST_POSTGRES_URL.');
}

$connection = DriverManager::getConnection(new DsnParser()->parse($url));
$settings = [];

foreach (Durability::SETTINGS as $setting) {
    $value = $connection->fetchOne('SHOW '.$setting);
    $settings[$setting] = is_string($value) ? $value : 'unknown';
}

$connection->close();
$wrong = Durability::mismatches($settings, $expected);

if ([] !== $wrong) {
    fwrite(\STDERR, sprintf("PostgreSQL isn't set up for this benchmark run: %s.\n", implode('; ', $wrong)));

    exit(1);
}
