<?php

declare(strict_types=1);

namespace YorvoID\Testing;

use PHPUnit\Framework\Assert;
use YorvoID\Testing\Contract\Races;

/**
 * Runs one of the concurrency contracts' races (TOOLING-03): several processes, each with its own stores
 * from your factory file, act at the same moment, round after round.
 *
 * In each round, every process first does what comes before the race (e.g. finds the token), then waits
 * until all have, and then all act at once (e.g. use it). A race of one call (use, claim, take) has
 * nothing before: the barrier starts the calls together, and what decides the race is the database.
 * That catches a store that checks and then acts often, not always; making the act one atomic
 * statement stays the store's duty.
 *
 * The factory file returns the stores:
 *
 * ```php
 * // tests/race-stores.php
 * return static function (string $type, string $implementation, string $test): object {
 *     // $type is a store's interface, e.g. OneTimeTokenStore::class, or Transactions::class for the
 *     // transactions the stores take part in. The same $test reaches the same records, in every
 *     // process; a new one starts empty, e.g. as a table prefix or a schema of its own.
 * };
 * ```
 *
 * @api
 */
final class Race
{
    /** How long a race may take before it fails */
    public const float LIMIT = 30.0;

    private readonly string $factory;

    /** @var array<string, \Closure(string, string, string): object> */
    private static array $factories = [];

    /**
     * @param string       $factory   The factory file: a readable .php file
     * @param positive-int $processes
     */
    public function __construct(string $factory, public readonly int $processes = 4)
    {
        $real = realpath($factory);

        if (false === $real || !is_file($real) || !is_readable($real) || !str_ends_with($real, '.php')) {
            throw new \InvalidArgumentException(\sprintf('The race\'s factory "%s" isn\'t a readable .php file.', $factory));
        }

        $this->factory = $real;
    }

    /**
     * The stores of a test, in this process: for setting up what the processes race for, and checking
     * what they left.
     */
    public function store(string $type, string $implementation, string $test): object
    {
        return self::factory($this->factory)($type, $implementation, $test);
    }

    /**
     * Runs the race: one round for each arguments given, every process in each.
     *
     * @param string                          $case   One of Races' cases
     * @param list<array<string, int|string>> $rounds
     *
     * @return list<list<mixed>> Each round's results, one for each process, in no order
     */
    public function run(string $case, string $implementation, string $test, array $rounds): array
    {
        Races::named($case);
        $directory = \sprintf('%s/yorvoid-race-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        mkdir($directory, 0o700);
        file_put_contents($directory.'/rounds.json', json_encode($rounds, \JSON_THROW_ON_ERROR));
        $processes = [];

        try {
            for ($i = 0; $i < $this->processes; ++$i) {
                $process = proc_open(
                    [\PHP_BINARY, \dirname(__DIR__).'/bin/race.php', self::autoload(), $this->factory, $implementation, $test, $case, $directory, (string) $i],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$directory}/out-{$i}", 'w'], 2 => ['file', "{$directory}/error-{$i}", 'w']],
                    $pipes,
                );
                Assert::assertIsResource($process, 'A race\'s process didn\'t start.');
                $processes[$i] = $process;
            }

            $deadline = microtime(true) + self::LIMIT;

            foreach (array_keys($rounds) as $round) {
                $this->waitUntilReady($processes, $directory, $round, $deadline);
                touch("{$directory}/go-{$round}");
            }

            $results = array_fill(0, \count($rounds), []);

            foreach ($processes as $i => $process) {
                // Only the first look after it ended has its exit code.
                $status = proc_get_status($process);

                while ($status['running']) {
                    self::before($deadline, $directory);
                    usleep(1_000);
                    $status = proc_get_status($process);
                }

                $status = $status['exitcode'];
                proc_close($process);
                unset($processes[$i]);
                Assert::assertSame(0, $status, \sprintf('A race\'s process failed: %s', self::errors($directory)));
                $out = json_decode((string) file_get_contents("{$directory}/out-{$i}"), true, flags: \JSON_THROW_ON_ERROR);
                Assert::assertIsArray($out);

                foreach (array_values($out) as $round => $result) {
                    $results[$round][] = $result;
                }
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                proc_terminate($process, 9);
                proc_close($process);
            }

            foreach (self::files($directory.'/*') as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }

    /**
     * @param array<int, resource> $processes
     */
    private function waitUntilReady(array $processes, string $directory, int $round, float $deadline): void
    {
        while (\count(self::files("{$directory}/ready-{$round}-*")) < $this->processes) {
            foreach ($processes as $process) {
                if (!proc_get_status($process)['running']) {
                    Assert::fail(\sprintf('A race\'s process ended before round %d: %s', $round, self::errors($directory)));
                }
            }

            self::before($deadline, $directory);
            usleep(1_000);
        }
    }

    private static function before(float $deadline, string $directory): void
    {
        if (microtime(true) > $deadline) {
            Assert::fail(\sprintf('The race took more than %d seconds: %s', self::LIMIT, self::errors($directory)));
        }
    }

    private static function errors(string $directory): string
    {
        $errors = array_filter(array_map(static fn (string $file): string => trim((string) file_get_contents($file)), self::files($directory.'/error-*')), static fn (string $error): bool => '' !== $error);

        return [] === $errors ? 'nothing on its error output' : implode(' | ', $errors);
    }

    /**
     * @return list<string>
     */
    private static function files(string $pattern): array
    {
        $files = glob($pattern);

        return false === $files ? [] : $files;
    }

    /**
     * Composer's autoloader, for the processes.
     */
    private static function autoload(): string
    {
        $loader = new \ReflectionClass(\Composer\Autoload\ClassLoader::class)->getFileName();
        \assert(\is_string($loader));

        return \dirname($loader, 2).'/autoload.php';
    }

    /**
     * @return \Closure(string, string, string): object
     *
     * @internal For Race and its processes
     */
    public static function factory(string $file): \Closure
    {
        if (isset(self::$factories[$file])) {
            return self::$factories[$file];
        }

        $factory = require $file;

        if (!$factory instanceof \Closure) {
            throw new \UnexpectedValueException(\sprintf('The race\'s factory "%s" doesn\'t return a Closure.', $file));
        }

        return self::$factories[$file] = static function (string $type, string $implementation, string $test) use ($factory, $file): object {
            $store = $factory($type, $implementation, $test);

            if (!$store instanceof $type) {
                throw new \UnexpectedValueException(\sprintf('The race\'s factory "%s" gave %s for %s.', $file, get_debug_type($store), $type));
            }

            return $store;
        };
    }
}
