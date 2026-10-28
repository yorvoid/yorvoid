<?php

declare(strict_types=1);

/*
 * A separate process for ThrottleConcurrencyTest: makes one attempt for the key "concurrent", limited
 * to 3 per hour.
 *
 *   php hit-throttle.php <database URL> <schema> <start file>
 *
 * Exits with 0 if the attempt was counted, 3 if it was throttled. Reading the key, before writing it,
 * pauses, so that every process has read it before any writes. Like consume-token.php, it gives up if
 * the start file doesn't appear.
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
use YorvoID\Throttle\ThrottleStore;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = $_SERVER['argv'];
assert(is_array($arguments) && 4 === count($arguments));
[, $url, $schema, $start] = array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', $arguments);

$database = new Database(DriverManager::getConnection(new DsnParser()->parse($url)), new StorageLocation(StorageLocation::DEFAULT_PREFIX, $schema));

$slow = new readonly class(new DbalThrottleStore($database)) implements ThrottleStore {
    public function __construct(
        private ThrottleStore $store,
    ) {
    }

    public function clearAt(string $key): ?int
    {
        $clearAt = $this->store->clearAt($key);
        usleep(300_000);

        return $clearAt;
    }

    public function replace(string $key, ?int $expected, int $clearAt): bool
    {
        return $this->store->replace($key, $expected, $clearAt);
    }

    public function remove(string $key): void
    {
        $this->store->remove($key);
    }

    public function purge(int $now): int
    {
        return $this->store->purge($now);
    }
};

$deadline = microtime(true) + 10;

while (!file_exists($start)) {
    if (microtime(true) > $deadline) {
        fwrite(\STDERR, "The start file never appeared.\n");

        exit(1);
    }

    usleep(1_000);
}

try {
    new Throttle($slow)->hit('concurrent', new Limit(3, Duration::hours(1)));
} catch (Throttled) {
    exit(3);
}

exit(0);
