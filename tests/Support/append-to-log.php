<?php

declare(strict_types=1);

/*
 * A separate process for SecurityLogConcurrencyTest: appends entries to a realm's security log.
 *
 *   php append-to-log.php <database URL> <schema> <realm> <count> <start file>
 *
 * It waits until the start file exists, so that every process starts appending at the same moment,
 * and gives up after a few seconds: if the test that started it died, it mustn't wait forever.
 */

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;
use YorvoID\Core\Storage\StorageLocation;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = $_SERVER['argv'];
assert(is_array($arguments) && 6 === count($arguments));
[, $url, $schema, $realm, $count, $start] = array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', $arguments);

$middleware = new TransactionMiddleware();
$configuration = new Configuration();
$configuration->setMiddlewares([$middleware]);
$connection = DriverManager::getConnection(new DsnParser()->parse($url), $configuration);
$database = new Database($connection, new StorageLocation(StorageLocation::DEFAULT_PREFIX, $schema));
$log = new SecurityLog(new DbalSecurityLogStore($database), new DbalTransactions($connection, $middleware));

$deadline = microtime(true) + 10;

while (!file_exists($start)) {
    if (microtime(true) > $deadline) {
        fwrite(\STDERR, "The start file never appeared.\n");

        exit(1);
    }

    usleep(1_000);
}

for ($i = 0; $i < (int) $count; ++$i) {
    $log->record(new Realm($realm), 'yorvoid.test.append', Outcome::Success, new Origin(OriginKind::Job, 'process '.getmypid()), details: ['i' => $i]);
}
