<?php

declare(strict_types=1);

/*
 * One process of a Race (yorvoid/testing): builds its stores from the factory file, and runs the race's
 * rounds. In each, it does what comes before the race, says it's ready, waits for the go, and acts.
 * What each act returned goes to its output, as JSON, at the end.
 *
 *   php race.php <autoload> <factory> <implementation> <test> <case> <directory> <index>
 *
 * Not run by hand: Race starts it, and stops it if it takes longer than Race::LIMIT.
 */

use YorvoID\Testing\Contract\Races;
use YorvoID\Testing\Race;

$arguments = $_SERVER['argv'] ?? null;

if (!is_array($arguments) || 8 !== count($arguments)) {
    fwrite(\STDERR, "Usage: race.php <autoload> <factory> <implementation> <test> <case> <directory> <index>\n");

    exit(64);
}

[, $autoload, $factory, $implementation, $test, $case, $directory, $index] = array_map(static fn (mixed $argument): string => is_string($argument) ? $argument : '', $arguments);

foreach (['autoload' => $autoload, 'factory' => $factory] as $what => $file) {
    if (!is_file($file) || !is_readable($file) || !str_ends_with($file, '.php')) {
        fwrite(\STDERR, "The {$what} \"{$file}\" isn't a readable .php file.\n");

        exit(64);
    }
}

require $autoload;

try {
    $stores = Race::factory($factory);
    $get = static fn (string $type): object => $stores($type, $implementation, $test);
    $race = Races::named($case);
    $rounds = json_decode((string) file_get_contents($directory.'/rounds.json'), true, flags: \JSON_THROW_ON_ERROR);
    assert(is_array($rounds));
    /** @var list<array<string, int|string>> $rounds */
    $deadline = microtime(true) + Race::LIMIT;
    $results = [];

    foreach ($rounds as $round => $arguments) {
        $before = ($race->before)($get, $arguments);
        touch("{$directory}/ready-{$round}-{$index}");

        while (!file_exists("{$directory}/go-{$round}")) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("No go for round {$round}.");
            }

            usleep(200);
        }

        $results[] = ($race->act)($get, $arguments, $before);
    }

    echo json_encode($results, \JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(\STDERR, "Process {$index}: ".$exception::class.': '.$exception->getMessage()."\n");

    exit(1);
}
