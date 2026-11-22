<?php

declare(strict_types=1);

/*
 * Splits the full mutation run into parts that run side by side, each within CI's time limit, and
 * prints the part asked for as the paths to give Infection, one per line.
 *
 *   php tools/infection/shards.php 3      prints the third part's paths
 *   php tools/infection/shards.php 3 7    the same, and fails unless there are 7 parts
 *   php tools/infection/shards.php        lists every part and how many files each has
 *
 * Every file Infection mutates (infection.json5's sources, as Infection collects them) is mutated by
 * exactly one part's paths, or this fails: a new package or directory, or a change to the sources,
 * isn't left out of the run without anyone noticing. CI runs it before each part, with the number of
 * parts its matrix runs, so a part added here but not there fails rather than never running. The
 * parts are balanced by how long CI took to mutate them, except the Symfony bundle's two halves,
 * split by lines of code until a run measures them.
 */

$root = dirname(__DIR__, 2);

// A directory's entries that Infection could mutate (directories and PHP files), except those named,
// as paths: so a new directory or file lands in the part that takes the rest of its directory.
$rest = static function (string $directory, array $except) use ($root): array {
    $paths = [];

    foreach (false === ($list = scandir($root.'/'.$directory)) ? [] : $list as $entry) {
        $isDirectory = is_dir($root.'/'.$directory.'/'.$entry);

        if ('.' === $entry[0] || in_array($entry, $except, true) || !($isDirectory || str_ends_with($entry, '.php'))) {
            continue;
        }

        $paths[] = $directory.'/'.$entry.($isDirectory ? '/' : '');
    }

    return $paths;
};

// The parts, numbered from 1 in this order. The core and the Symfony bundle, the packages slowest to
// mutate, are each split in two.
$parts = [
    ['packages/core/src/Authorization/', 'packages/core/src/Storage/'],
    array_merge($rest('packages/core', ['src']), $rest('packages/core/src', ['Authorization', 'Storage'])),
    ['packages/authn/', 'packages/throttle/', 'packages/mail/'],
    ['packages/symfony/src/Command/', 'packages/symfony/src/Controller/', 'packages/symfony/src/Templates/', 'packages/symfony/src/Factories.php'],
    array_merge($rest('packages/symfony', ['src']), $rest('packages/symfony/src', ['Command', 'Controller', 'Templates', 'Factories.php'])),
    ['packages/policy/', 'packages/policy-testing/', 'packages/relations/', 'packages/phpstan-rules/'],
    ['packages/passkeys/', 'packages/intent-verifier/', 'packages/flows/', 'packages/notifications/', 'packages/testing/', 'packages/translation/', 'packages/virtual-authenticator/', 'packages/intent-signing/'],
];
$parts = array_combine(range(1, count($parts)), $parts);

// What Infection mutates, as Infection's own command lists it, from infection.json5's sources and with
// each part's paths: never a copy of those sources here, nor of Infection's code, which could drift.
$collect = static function (array $paths) use ($root): array {
    $command = escapeshellarg(\PHP_BINARY).' '.escapeshellarg($root.'/vendor/bin/infection').' config:list-sources --no-ansi --configuration='.escapeshellarg($root.'/infection.json5');

    foreach ($paths as $path) {
        $command .= ' '.escapeshellarg(is_string($path) ? $path : '');
    }

    $lines = [];
    exec($command, $lines, $status);

    if (0 !== $status) {
        fwrite(\STDERR, "Infection couldn't list the sources (vendor/bin/infection config:list-sources).\n");

        exit(1);
    }

    $files = [];

    foreach ($lines as $line) {
        if (str_ends_with($line = trim($line), '.php')) {
            $files[] = $line;
        }
    }

    return $files;
};

$files = $collect([]);
$inPart = array_map($collect, $parts);
$counts = array_map(count(...), $inPart);
$wrong = [];

foreach ($files as $path) {
    $in = array_keys(array_filter($inPart, static fn (array $mutated): bool => in_array($path, $mutated, true)));

    if (1 !== count($in)) {
        $wrong[] = sprintf('%s is in %s.', $path, [] === $in ? 'no part' : 'parts '.implode(', ', $in));
    }
}

foreach (array_diff(array_merge(...array_values($inPart)), $files) as $path) {
    $wrong[] = sprintf('%s is in a part, but not among the sources.', $path);
}

if ([] !== $wrong) {
    fwrite(\STDERR, "Every file mutated must be in exactly one part (tools/infection/shards.php):\n".implode("\n", $wrong)."\n");

    exit(1);
}

$asked = $argv[1] ?? null;
$total = $argv[2] ?? null;

if (null !== $total && (string) count($parts) !== $total) {
    fwrite(\STDERR, sprintf("CI runs %s parts, but there are %d (tools/infection/shards.php).\n", $total, count($parts)));

    exit(1);
}

if (null === $asked) {
    foreach ($parts as $part => $fragments) {
        printf("%d: %d files, %s\n", $part, $counts[$part], implode(' ', $fragments));
    }

    exit(0);
}

if (!isset($parts[(int) $asked]) || (string) (int) $asked !== $asked) {
    fwrite(\STDERR, sprintf("No part %s: there are %d.\n", $asked, count($parts)));

    exit(1);
}

echo implode("\n", $parts[(int) $asked]), "\n";
