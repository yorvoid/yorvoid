<?php

declare(strict_types=1);

/*
 * The benchmarks' tables and gate (M2-8), from phpbench's dumps (composer bench):
 *
 *   php tools/bench/bench.php report <run.xml>                          the table: median, p99, target
 *   php tools/bench/bench.php compare <base.xml> <head.xml> [<changed>]  fails when a subject's median is
 *                                                                        more than 20 % above the base's
 *
 * Each of base and head may be several dumps, separated by commas: their iterations are pooled, as
 * ci.sh measures base and head in turns (ABBA).
 *
 * The centre is the median of the iterations, everywhere: robust where a subject's times have two
 * peaks, which a mode jumps between. <changed> lists the subjects
 * whose benchmark code differs between base and head, one per line, "Class::subject", "Class::*" or
 * "*" for every one: they and the new ones are reported "changed, not gated" (C3). Tables go to stdout as Markdown,
 * for the job summary.
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

const THRESHOLD = 20.0;

/**
 * @param string $files One dump, or several separated by commas: their iterations pooled per subject
 *
 * @return array<string, list<float>> Each subject's iterations, in microseconds
 */
function iterations(string $files): array
{
    $subjects = [];

    foreach (array_filter(explode(',', $files), static fn (string $file): bool => '' !== $file) as $file) {
        foreach (dump($file) as $subject => $times) {
            $subjects[$subject] = [...$subjects[$subject] ?? [], ...$times];
        }
    }

    return $subjects;
}

/**
 * @return array<string, list<float>>
 */
function dump(string $file): array
{
    $xml = simplexml_load_file($file);

    if (false === $xml) {
        throw new RuntimeException(sprintf('%s isn\'t a phpbench dump.', $file));
    }

    $subjects = [];
    $benchmarks = $xml->xpath('//benchmark');

    foreach ($benchmarks ?? [] as $benchmark) {
        $parts = explode('\\', (string) $benchmark['class']);
        $class = end($parts);

        foreach ($benchmark->subject as $subject) {
            foreach ($subject->variant as $variant) {
                $times = [];

                foreach ($variant->iteration as $iteration) {
                    $times[] = (float) $iteration['time-avg'];
                }

                $subjects[$class.'::'.$subject['name']] = $times;
            }
        }
    }

    return $subjects;
}

/**
 * @param list<float> $times
 */
function median(array $times): float
{
    sort($times);
    $middle = intdiv(count($times), 2);

    return 0 === count($times) % 2 ? ($times[$middle - 1] + $times[$middle]) / 2 : $times[$middle];
}

/**
 * @param list<float> $times
 */
function p99(array $times): float
{
    sort($times);

    return $times[(int) max(0, ceil(0.99 * count($times)) - 1)] ?? 0.0;
}

function ms(float $microseconds): string
{
    return number_format($microseconds / 1000, 3).' ms';
}

$command = $argv[1] ?? '';

if ('report' === $command && isset($argv[2])) {
    /** @var array<string, array{p99: int, requirement: string}> $targets */
    $targets = require __DIR__.'/targets.php';
    echo "| Subject | Median | p99 | Target |\n|---|---|---|---|\n";

    foreach (iterations($argv[2]) as $subject => $times) {
        $target = $targets[$subject] ?? null;
        $verdict = null === $target ? '' : sprintf('%s: %s', p99($times) < $target['p99'] ? 'met' : '**over**', $target['requirement']);
        printf("| %s | %s | %s | %s |\n", $subject, ms(median($times)), ms(p99($times)), $verdict);
    }

    exit(0);
}

if ('compare' === $command && isset($argv[2], $argv[3])) {
    $base = iterations($argv[2]);
    $head = iterations($argv[3]);
    $lines = isset($argv[4]) && is_file($argv[4]) ? file($argv[4]) : false;
    $changed = array_values(array_filter(array_map(trim(...), false === $lines ? [] : $lines), static fn (string $line): bool => '' !== $line));
    $failed = false;
    echo "| Subject | Base (median) | Head (median) | Change | |\n|---|---|---|---|---|\n";

    foreach ($head as $subject => $times) {
        $class = explode('::', $subject)[0].'::*';

        if (!isset($base[$subject]) || in_array('*', $changed, true) || in_array($subject, $changed, true) || in_array($class, $changed, true)) {
            printf("| %s | %s | %s | | changed, not gated |\n", $subject, isset($base[$subject]) ? ms(median($base[$subject])) : 'new', ms(median($times)));

            continue;
        }

        $change = 100.0 * (median($times) - median($base[$subject])) / median($base[$subject]);
        $slower = $change > THRESHOLD;
        $failed = $failed || $slower;
        printf("| %s | %s | %s | %+.1f %% | %s |\n", $subject, ms(median($base[$subject])), ms(median($times)), $change, $slower ? '**slower**' : 'ok');
    }

    exit($failed ? 1 : 0);
}

fwrite(\STDERR, "Usage: php tools/bench/bench.php report <run.xml> | compare <base.xml> <head.xml> [<changed>]\n");
exit(2);
