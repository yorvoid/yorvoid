<?php

declare(strict_types=1);

/*
 * After a release: whether its packages install together from Packagist, as an application gets them
 * (ADR-0005). It installs every package Split pushes (.github/split.json) at that version into an empty
 * project with Composer, and tries again until it does or 30 minutes have passed.
 *
 *   php tools/release/installs.php v0.4.0
 */

$root = dirname(__DIR__, 2);
$tag = $argv[1] ?? '';

if (1 !== preg_match('/^v(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$/D', $tag, $match)) {
    fwrite(\STDERR, "Usage: php tools/release/installs.php vX.Y.Z\n");
    exit(2);
}

$version = $match[1];
$split = json_decode((string) file_get_contents($root.'/.github/split.json'), true, flags: \JSON_THROW_ON_ERROR);
assert(is_array($split));
$packages = [];

foreach ($split as $entry) {
    assert(is_array($entry) && is_string($entry['repository']));
    $packages[] = $entry['repository'];
}

$work = sys_get_temp_dir().'/yorvoid-installs-'.bin2hex(random_bytes(4));
mkdir($work);
file_put_contents($work.'/composer.json', json_encode([
    'name' => 'acme/installs',
    'type' => 'project',
    'minimum-stability' => str_contains($version, '-') ? 'dev' : 'stable',
    'prefer-stable' => true,
    'require' => array_fill_keys($packages, $version),
    'config' => ['allow-plugins' => false],
], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

// Packagist lists a tag a few minutes after Split pushed it. Composer itself asks, as an application's
// Composer does (its own HTTP client, User-Agent, retries and certificates), until the set installs. Each
// round's error is shown when it differs from the last one, so the log says why it doesn't install yet.
$deadline = time() + 30 * 60;
$last = null;
$lines = [];

echo sprintf("Installing every package at %s from Packagist:\n", $version);

while (true) {
    exec(sprintf('cd %s && composer update --no-interaction --no-progress --no-scripts --no-audit 2>&1', escapeshellarg($work)), $lines, $status);
    $output = implode("\n", $lines);
    $lines = [];

    if (0 === $status) {
        echo $output, "\n";

        break;
    }

    if ($output !== $last) {
        echo $output, "\n";
        $last = $output;
    }

    if (time() > $deadline) {
        fwrite(\STDERR, sprintf("::error::The packages of %s don't install together from Packagist after 30 minutes: see Composer's output above.\n", $version));
        exit(1);
    }

    echo sprintf("%s: not yet, trying again in 30 seconds…\n", gmdate('H:i:s'));
    sleep(30);
}

$lock = json_decode((string) file_get_contents($work.'/composer.lock'), true, flags: \JSON_THROW_ON_ERROR);
$installed = [];

assert(is_array($lock) && is_array($lock['packages']));

foreach ($lock['packages'] as $package) {
    assert(is_array($package) && is_string($package['name']) && is_string($package['version']));

    if (str_starts_with($package['name'], 'yorvoid/')) {
        $installed[$package['name']] = ltrim($package['version'], 'v');
    }
}

$wrong = array_filter($installed, static fn (string $installedVersion): bool => $installedVersion !== $version);

if ([] !== $wrong || count($installed) !== count($packages)) {
    fwrite(\STDERR, sprintf("::error::Not every package installed at %s: %s\n", $version, json_encode($installed, \JSON_UNESCAPED_SLASHES)));
    exit(1);
}

echo sprintf("All %d packages install together at %s.\n", count($installed), $version);
