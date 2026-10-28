<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Appends from separate processes at the same moment never fork the chain (PLT-025): each entry gets
 * its own number and links to the one before it.
 */
final class SecurityLogConcurrencyTest extends DatabaseTestCase
{
    private const int PROCESSES = 4;

    private const int ENTRIES_EACH = 25;

    public function testConcurrentAppendsFromSeparateProcessesKeepOneChain(): void
    {
        $stack = $this->stack('postgresql');
        $database = $stack->database;
        \assert($database instanceof Database && null !== $database->location->schema);
        $start = \sprintf('%s/yorvoid-start-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $processes = [];

        for ($i = 0; $i < self::PROCESSES; ++$i) {
            $process = proc_open(
                [\PHP_BINARY, __DIR__.'/../Support/append-to-log.php', (string) getenv('YORVOID_TEST_POSTGRES_URL'), $database->location->schema, 'acme', (string) self::ENTRIES_EACH, $start],
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

            // If anything went wrong above, no process is left behind holding a connection.
            foreach ($processes as [$process]) {
                proc_terminate($process);
            }
        }

        foreach ($results as [$exitCode, $output]) {
            self::assertSame(0, $exitCode, 'A process failed: '.$output);
        }

        $log = new SecurityLog($stack->log, $stack->transactions);
        $verification = $log->verify(new Realm('acme'));
        self::assertTrue($verification->isIntact(), (string) $verification->problem);
        self::assertSame(self::PROCESSES * self::ENTRIES_EACH, $verification->entriesChecked);

        $writers = array_unique(array_map(static fn (LogEntry $entry): ?string => $entry->origin->detail, iterator_to_array($stack->log->entries(new Realm('acme'), 1), false)));
        self::assertCount(self::PROCESSES, $writers, 'Not every process appended.');
    }
}
