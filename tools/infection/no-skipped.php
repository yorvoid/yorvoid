<?php

declare(strict_types=1);

/*
 * Fails when Infection skipped mutants. It skips those whose covering tests take longer than its
 * timeout, and leaves them out of the score, so a score can say 100% with half the mutants untried.
 *
 *   php tools/infection/no-skipped.php        checks var/infection/summary.json (see infection.json5)
 *   php tools/infection/no-skipped.php clear  removes it, before a run
 *
 * "composer infection" runs both, around Infection. Infection writes no summary when there's nothing to
 * mutate, e.g. a pull request that changes no code. "composer infection -- …" passes the run's
 * arguments to these too, after "clear": the rest are ignored.
 */

$file = dirname(__DIR__, 2).'/var/infection/summary.json';

if ('clear' === ($argv[1] ?? null)) {
    is_file($file) && unlink($file);

    exit(0);
}

if (!file_exists($file)) {
    echo "Nothing was mutated.\n";

    exit(0);
}

$summary = json_decode((string) file_get_contents($file), true);
$skipped = is_array($summary) && is_array($summary['stats'] ?? null) ? ($summary['stats']['skippedCount'] ?? null) : null;

if (!is_int($skipped)) {
    fwrite(\STDERR, sprintf("Infection's summary %s is unreadable.\n", $file));

    exit(1);
}

if ($skipped > 0) {
    fwrite(\STDERR, sprintf("Infection skipped %d mutants: their tests take longer than its timeout, so they weren't tried. Raise \"timeout\" in infection.json5.\n", $skipped));

    exit(1);
}

echo "No mutant was skipped.\n";
