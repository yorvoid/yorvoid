<?php

declare(strict_types=1);

/*
 * Makes the coverage Infection mutates against, with ParaTest: coverage-xml and JUnit in
 * var/infection/coverage, so Infection skips its own, serial, initial run (--skip-initial-tests).
 *
 *   php tools/infection/coverage.php                          runs the suite with coverage
 *   YORVOID_COVERAGE=reuse php tools/infection/coverage.php   keeps the coverage that's there, made
 *                                                             for this very commit (CI's test job)
 *
 * "composer infection" runs it before Infection. Reused coverage must name the commit it was made
 * for, in var/infection/coverage/commit, and that must be the commit checked out, with nothing
 * uncommitted: coverage of another tree would point Infection at the wrong tests. The run's arguments ("composer infection -- …")
 * are Infection's, and ignored here.
 */

$root = dirname(__DIR__, 2);
$directory = $root.'/var/infection/coverage';
$head = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
// Uncommitted changes, tracked or new: coverage of HEAD wouldn't be coverage of this tree.
$changed = trim((string) shell_exec('git -C '.escapeshellarg($root).' status --porcelain 2>/dev/null'));

if ('reuse' === getenv('YORVOID_COVERAGE')) {
    $made = is_file($directory.'/commit') ? trim((string) file_get_contents($directory.'/commit')) : '';

    if ('' === $head || $made !== $head || !is_file($directory.'/junit.xml') || !is_dir($directory.'/coverage-xml')) {
        fwrite(\STDERR, sprintf("No coverage of this commit (%s) to reuse: it was made for %s.\n", '' === $head ? 'unknown' : $head, '' === $made ? 'none' : $made));
        exit(1);
    }

    if ('' !== $changed) {
        fwrite(\STDERR, "The working tree has uncommitted changes: the coverage of {$head} isn't theirs. Commit them, or run without YORVOID_COVERAGE=reuse.\n");
        exit(1);
    }

    echo "Reusing the coverage of {$head}.\n";
    exit(0);
}

$command = [\PHP_BINARY, '-d', 'memory_limit=4G', $root.'/vendor/bin/paratest', '--coverage-xml='.$directory.'/coverage-xml', '--log-junit='.$directory.'/junit.xml'];
passthru(implode(' ', array_map('escapeshellarg', $command)), $status);

if (0 !== $status) {
    exit($status);
}

file_put_contents($directory.'/commit', $head."\n");
