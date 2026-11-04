<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Six requests make an attempt for the same key at the same moment, each in its own process, against a
 * limit of three: exactly three are counted (ATN-040). Each reads the key before any writes it, so
 * reading and then writing, without the write checking what was read, would let them all through.
 */
final class ThrottleConcurrencyTest extends DatabaseTestCase
{
    private const int PROCESSES = 6;

    public function testConcurrentAttemptsDontSlipPastTheLimit(): void
    {
        self::assertSame([0, 0, 0, 3, 3, 3], $this->attempts(3), 'Expected three attempts to be counted.');
    }

    public function testConcurrentAttemptsUnderTheLimitAreAllCounted(): void
    {
        self::assertSame([0, 0, 0, 0, 0, 0], $this->attempts(100), 'An attempt under the limit was refused because others came at once (PERF-05).');
    }

    /**
     * @return list<int> Each process's exit code, sorted: 0 counted, 3 refused
     */
    private function attempts(int $limit): array
    {
        $database = $this->stack('postgresql')->database;
        \assert($database instanceof Database && null !== $database->location->schema);
        $start = \sprintf('%s/yorvoid-start-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $processes = [];

        for ($i = 0; $i < self::PROCESSES; ++$i) {
            $process = proc_open(
                [\PHP_BINARY, __DIR__.'/../Support/hit-throttle.php', (string) getenv('YORVOID_TEST_POSTGRES_URL'), $database->location->schema, $start, (string) $limit],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        touch($start);
        $results = [];

        try {
            foreach ($processes as $i => [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
                $results[] = [proc_close($process), $output];
                unset($processes[$i]);
            }
        } finally {
            unlink($start);

            foreach ($processes as [$process]) {
                proc_terminate($process);
            }
        }

        $exitCodes = array_column($results, 0);
        sort($exitCodes);
        self::assertSame([], array_diff($exitCodes, [0, 3]), implode(' | ', array_column($results, 1)));

        return $exitCodes;
    }
}
