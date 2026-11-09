<?php

declare(strict_types=1);

/*
 * The benchmarks' tables and gate (M2-8), from tools/bench/run.php's dumps (composer bench):
 *
 *   php tools/bench/bench.php report <run.json>                         the table: median, p99, target
 *   php tools/bench/bench.php compare <base.json> <head.json> [<changed>]  fails when a subject's lower quartile is
 *                                                                        more than 20 % above the base's
 *
 *   php tools/bench/bench.php drift <release.json> <head.json>         the change since a release, in the p25, the
 *                                                                        median and the p99; gates nothing
 *   php tools/bench/bench.php record <directory> <runs>                the A/A record from the runs' changes:
 *                                                                        fails on a failure, a subject whose
 *                                                                        worst |change| is over 15 %, or fewer
 *                                                                        runs than there should be
 *
 * Each of base and head may be several dumps, separated by commas: their iterations are pooled, as
 * ci.sh measures base and head in turns (ABBA). A subject the base has and the head doesn't is
 * "removed, not gated", and the table says first how many weren't.
 *
 * The gate compares the lower quartile (p25) of the iterations: the A/A record on GitHub's runners showed
 * the median moving by up to 47 % between runs of the same code, as bursts of the runner's noise slowed
 * whole turns. The table of the targets gives the median and the p99. <changed> lists the subjects
 * whose benchmark code differs between base and head, one per line, "Class::subject", "Class::*" or
 * "*" for every one: they and the new ones are reported "changed, not gated" (C3). Tables go to stdout as Markdown,
 * for the job summary.
 */

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
 * A dump's subjects and their times; refused when it isn't one: a time that isn't a number, or a subject
 * without any, would make a centre of nothing.
 *
 * @return array<string, list<float>>
 *
 * @throws RuntimeException
 */
function dump(string $file): array
{
    $data = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);

    if (!is_array($data) || !is_array($data['subjects'] ?? null)) {
        throw new RuntimeException(sprintf('%s isn\'t a dump of tools/bench/run.php.', $file));
    }

    $subjects = [];

    foreach ($data['subjects'] as $subject => $times) {
        if (!is_array($times) || [] === $times) {
            throw new RuntimeException(sprintf('%s has no times for %s.', $file, $subject));
        }

        foreach ($times as $time) {
            if (!is_int($time) && !is_float($time)) {
                throw new RuntimeException(sprintf('%s has a time for %s that isn\'t a number: %s.', $file, $subject, json_encode($time)));
            }
        }

        $subjects[(string) $subject] = array_values(array_map(floatval(...), $times));
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
 * The lower quartile: what the gate compares. A shared runner's noise only ever adds time, so a burst
 * that slows some turns moves the median, and leaves the quartile to the turns it didn't reach.
 *
 * @param list<float> $times
 */
function p25(array $times): float
{
    sort($times);

    return $times[(int) max(0, ceil(0.25 * count($times)) - 1)] ?? 0.0;
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
    $rows = [];
    $changes = [];
    $ungated = 0;

    foreach ($head as $subject => $times) {
        $class = explode('::', $subject)[0].'::*';

        if (!isset($base[$subject]) || in_array('*', $changed, true) || in_array($subject, $changed, true) || in_array($class, $changed, true)) {
            $rows[] = sprintf('| %s | %s | %s | | changed, not gated |', $subject, isset($base[$subject]) ? ms(p25($base[$subject])) : 'new', ms(p25($times)));
            ++$ungated;

            continue;
        }

        $change = 100.0 * (p25($times) - p25($base[$subject])) / p25($base[$subject]);
        $slower = $change > THRESHOLD;
        $failed = $failed || $slower;
        $changes[$subject] = round($change, 2);
        $rows[] = sprintf('| %s | %s | %s | %+.1f %% | %s |', $subject, ms(p25($base[$subject])), ms(p25($times)), $change, $slower ? '**slower**' : 'ok');
    }

    // A subject the head doesn't have any more, e.g. renamed, isn't compared: said so, not left out.
    foreach (array_diff_key($base, $head) as $subject => $times) {
        $rows[] = sprintf('| %s | %s | | | removed, not gated |', $subject, ms(p25($times)));
        ++$ungated;
    }

    printf("%d of %d subjects not gated.\n\n| Subject | Base (p25) | Head (p25) | Change | |\n|---|---|---|---|---|\n%s\n", $ungated, count($rows), implode("\n", $rows));

    // The changes, for the A/A record.
    if (isset($argv[5])) {
        file_put_contents($argv[5], json_encode($changes, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
    }

    exit($failed ? 1 : 0);
}

if ('drift' === $command && isset($argv[2], $argv[3])) {
    // Since the last release (main): the change in each statistic, gating nothing. The median and the
    // p99 judge a release, as the lower quartile doesn't see a regression that only lengthens the tail.
    $base = iterations($argv[2]);
    $head = iterations($argv[3]);
    echo "| Subject | p25 | Median | p99 |\n|---|---|---|---|\n";

    foreach ($head as $subject => $times) {
        if (!isset($base[$subject])) {
            printf("| %s | new | new | new |\n", $subject);

            continue;
        }

        $delta = static fn (float $before, float $after): string => sprintf('%+.1f %%', 100.0 * ($after - $before) / $before);
        printf("| %s | %s | %s | %s |\n", $subject, $delta(p25($base[$subject]), p25($times)), $delta(median($base[$subject]), median($times)), $delta(p99($base[$subject]), p99($times)));
    }

    exit(0);
}

if ('record' === $command && isset($argv[2], $argv[3])) {
    // The A/A record (C1): each subject's largest |change| across the runs, and how many failed.
    $files = glob(rtrim($argv[2], '/').'/*.json');
    $worst = [];
    $failures = [];
    $runs = 0;

    foreach (false === $files ? [] : $files as $file) {
        $changes = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        ++$runs;

        foreach (is_array($changes) ? $changes : [] as $subject => $change) {
            if (!is_int($change) && !is_float($change)) {
                throw new RuntimeException(sprintf('%s has a change for %s that isn\'t a number.', $file, $subject));
            }

            $worst[$subject] = max($worst[$subject] ?? 0.0, abs((float) $change));
            $failures[$subject] = ($failures[$subject] ?? 0) + ($change > THRESHOLD ? 1 : 0);
        }
    }

    ksort($worst);
    printf("%d A/A runs.\n\n| Subject | Worst change | Failures |\n|---|---|---|\n", $runs);
    $over = false;

    foreach ($worst as $subject => $change) {
        // Three quarters of the gate: a subject this jumpy is fixed at its source, not by a wider gate.
        $over = $over || $change > 0.75 * THRESHOLD || $failures[$subject] > 0;
        printf("| %s | %.1f %% | %d |\n", $subject, $change, $failures[$subject]);
    }

    // A run that left no changes failed before comparing: it counts as a failure.
    exit($over || $runs < (int) $argv[3] ? 1 : 0);
}

fwrite(\STDERR, "Usage: php tools/bench/bench.php report <run.json> | compare <base.json> <head.json> [<changed>] [<changes.json>] | drift <release.json> <head.json> | record <directory> <runs>\n");
exit(2);
