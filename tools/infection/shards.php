<?php

declare(strict_types=1);

/*
 * Splits the full mutation run into parts that run side by side, each within CI's time limit, and
 * prints the part asked for as the paths to give Infection, one per line.
 *
 *   php tools/infection/shards.php 3    prints the third part's paths
 *   php tools/infection/shards.php      lists every part and how many files each has
 *
 * Every file Infection mutates (infection.json5's sources, as Infection collects them) is mutated by
 * exactly one part's paths, or this fails: a new package or directory, or a change to the sources,
 * isn't left out of the run without anyone noticing. CI runs it before each part. The parts are
 * balanced by lines of code; the core is split in two, as the largest package.
 */

$root = dirname(__DIR__, 2);
$entries = static fn (string $directory): array => false === ($list = scandir($directory)) ? [] : $list;

// The core's two largest directories make a part; the rest of the core is the next.
$coreRest = [];

foreach ($entries($root.'/packages/core') as $entry) {
    if ('.' === $entry[0] || 'src' === $entry || !(is_dir($root.'/packages/core/'.$entry) || str_ends_with($entry, '.php'))) {
        continue;
    }

    $coreRest[] = 'packages/core/'.$entry.(is_dir($root.'/packages/core/'.$entry) ? '/' : '');
}

foreach ($entries($root.'/packages/core/src') as $entry) {
    if ('.' === $entry[0] || 'Authorization' === $entry || 'Storage' === $entry || !(is_dir($root.'/packages/core/src/'.$entry) || str_ends_with($entry, '.php'))) {
        continue;
    }

    $coreRest[] = 'packages/core/src/'.$entry.(is_dir($root.'/packages/core/src/'.$entry) ? '/' : '');
}

$parts = [
    1 => ['packages/core/src/Authorization/', 'packages/core/src/Storage/'],
    2 => $coreRest,
    3 => ['packages/authn/', 'packages/throttle/', 'packages/mail/'],
    4 => ['packages/symfony/', 'packages/translation/', 'packages/virtual-authenticator/', 'packages/intent-signing/'],
    5 => ['packages/policy/', 'packages/policy-testing/', 'packages/relations/', 'packages/phpstan-rules/'],
    6 => ['packages/passkeys/', 'packages/intent-verifier/', 'packages/flows/', 'packages/notifications/', 'packages/testing/'],
];

// What Infection mutates, as Infection's own command lists it, from infection.json5's sources and with
// each part's paths: never a copy of those sources here, nor of Infection's code, which could drift.
$collect = static function (array $paths) use ($root): array {
    $command = escapeshellarg(\PHP_BINARY).' '.escapeshellarg($root.'/vendor/bin/infection').' config:list-sources --no-ansi --configuration='.escapeshellarg($root.'/infection.json5');

    foreach ($paths as $path) {
        $command .= ' '.escapeshellarg(is_string($path) ? $path : '');
    }

    $lines = [];
    exec($command.' 2>/dev/null', $lines, $status);

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
