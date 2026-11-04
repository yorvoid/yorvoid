<?php

declare(strict_types=1);

/*
 * A separate process for ThrottleConcurrencyTest: makes one attempt for the key "concurrent", limited
 * to the given number per hour.
 *
 *   php hit-throttle.php <database URL> <schema> <start file> <attempts per hour>
 *
 * Exits with 0 if the attempt was counted, 3 if it was throttled. The processes wait for the start file,
 * so that they hit the key at once. Like consume-token.php, it gives up if the start file doesn't appear.
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use YorvoID\Core\Duration;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\StorageLocation;
use YorvoID\Throttle\Limit;
use YorvoID\Throttle\Storage\DbalThrottleStore;
use YorvoID\Throttle\Throttle;
use YorvoID\Throttle\Throttled;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = $_SERVER['argv'];
assert(is_array($arguments) && 5 === count($arguments));
[, $url, $schema, $start, $attempts] = array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', $arguments);

$database = new Database(DriverManager::getConnection(new DsnParser()->parse($url)), new StorageLocation(StorageLocation::DEFAULT_PREFIX, $schema));

$deadline = microtime(true) + 10;

while (!file_exists($start)) {
    if (microtime(true) > $deadline) {
        fwrite(\STDERR, "The start file never appeared.\n");

        exit(1);
    }

    usleep(1_000);
}

try {
    new Throttle(new DbalThrottleStore($database))->hit('concurrent', new Limit((int) $attempts, Duration::hours(1)));
} catch (Throttled) {
    exit(3);
}

exit(0);
