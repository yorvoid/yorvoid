<?php

declare(strict_types=1);

/*
 * Splits the full mutation run into parts that run side by side, each within CI's time limit, and
 * prints the part asked for as Infection's --filter: path fragments, comma-separated.
 *
 *   php tools/infection/shards.php 3    prints the third part's filter
 *   php tools/infection/shards.php      lists every part and how many files each has
 *
 * Every file Infection mutates (infection.json5's sources, as Infection collects them) is mutated by
 * exactly one part's filter, or this fails: a new package or directory, or a change to the sources,
 * isn't left out of the run without anyone noticing. CI runs it before each part. The parts are
 * balanced by lines of code; the core is split in two, as the largest package.
 */

use Infection\Configuration\SourceFilter\PlainFilter;
use Infection\Source\Collector\BasicSourceCollector;

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

// What Infection mutates, as Infection collects it from infection.json5's sources, and what it mutates
// with each part's filter: never a copy of those sources here, which could drift from the file.
require $root.'/vendor/autoload.php';

$config = json5_decode((string) file_get_contents($root.'/infection.json5'), true);
$source = is_array($config) && is_array($config['source'] ?? null) ? $config['source'] : [];
/** @var list<non-empty-string> $directories */
$directories = $source['directories'] ?? [];
/** @var list<non-empty-string> $excludes */
$excludes = $source['excludes'] ?? [];
$collect = static fn (?PlainFilter $filter): array => array_map(
    static fn (SplFileInfo $file): string => substr((string) $file->getRealPath(), strlen((string) realpath($root)) + 1),
    BasicSourceCollector::create($root.'/infection.json5', $directories, $excludes, $filter)->collect(),
);

$files = $collect(null);
$inPart = array_map(static fn (array $fragments): array => $collect(PlainFilter::tryToCreate(implode(',', $fragments))), $parts);
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
        printf("%d: %d files, %s\n", $part, $counts[$part], implode(',', $fragments));
    }

    exit(0);
}

if (!isset($parts[(int) $asked]) || (string) (int) $asked !== $asked) {
    fwrite(\STDERR, sprintf("No part %s: there are %d.\n", $asked, count($parts)));

    exit(1);
}

echo implode(',', $parts[(int) $asked]), "\n";
