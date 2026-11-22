<?php

declare(strict_types=1);

/*
 * Splits the full mutation run into parts that run side by side, each within CI's time limit, and
 * prints the part asked for as Infection's --filter: path fragments, comma-separated.
 *
 *   php tools/infection/shards.php 3    prints the third part's filter
 *   php tools/infection/shards.php      lists every part and how many files each has
 *
 * Every file Infection mutates (infection.json5's sources) is in exactly one part, or this fails:
 * a new package or directory isn't left out of the run without anyone noticing. The parts are
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

// What Infection mutates: every PHP file under packages, but those infection.json5 excludes.
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/packages', FilesystemIterator::SKIP_DOTS));

/** @var SplFileInfo $file */
foreach ($iterator as $file) {
    $path = substr($file->getPathname(), strlen($root) + 1);

    if (!str_ends_with($path, '.php') || 1 === preg_match('~/(vendor|node_modules)/|^packages/testing/src/Contract/~', $path)) {
        continue;
    }

    $files[] = $path;
}

$counts = array_fill_keys(array_keys($parts), 0);
$wrong = [];

foreach ($files as $path) {
    $in = array_keys(array_filter($parts, static fn (array $fragments): bool => [] !== array_filter($fragments, static fn (string $fragment): bool => str_starts_with($path, $fragment))));

    if (1 !== count($in)) {
        $wrong[] = sprintf('%s is in %s.', $path, [] === $in ? 'no part' : 'parts '.implode(', ', $in));
    }

    foreach ($in as $part) {
        ++$counts[$part];
    }
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
