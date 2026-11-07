<?php

declare(strict_types=1);

/*
 * After a release: whether its packages install together from Packagist, as an application gets them
 * (ADR-0005). It waits for Packagist to list the version of every package (it reads the repositories
 * Split pushes to), then installs all of them at that version into an empty project.
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

/** The versions Packagist lists for the package, without the tags' "v" */
$listed = static function (string $package): array {
    $metadata = @file_get_contents('https://repo.packagist.org/p2/'.$package.'.json');
    $decoded = false === $metadata ? [] : json_decode($metadata, true, flags: \JSON_THROW_ON_ERROR);
    $entries = is_array($decoded) && is_array($decoded['packages'] ?? null) && is_array($decoded['packages'][$package] ?? null) ? $decoded['packages'][$package] : [];
    $versions = [];

    foreach ($entries as $entry) {
        if (is_array($entry) && is_string($entry['version'] ?? null)) {
            $versions[] = ltrim($entry['version'], 'v');
        }
    }

    return $versions;
};
$waiting = $packages;
$deadline = time() + 30 * 60;

// Packagist updates from each repository's tag a few minutes after Split pushed it.
while ([] !== $waiting) {
    foreach ($waiting as $index => $package) {
        if (in_array($version, $listed($package), true)) {
            unset($waiting[$index]);
        }
    }

    if ([] === $waiting) {
        break;
    }

    if (time() > $deadline) {
        fwrite(\STDERR, sprintf("::error::Packagist doesn't list %s of %s after 30 minutes.\n", $version, implode(', ', $waiting)));
        exit(1);
    }

    echo sprintf("Waiting for Packagist to list %s of %s…\n", $version, implode(', ', $waiting));
    sleep(30);
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

echo sprintf("Installing every package at %s from Packagist:\n", $version);
passthru(sprintf('cd %s && composer update --no-interaction --no-progress --no-scripts --no-audit 2>&1', escapeshellarg($work)), $status);

if (0 !== $status) {
    fwrite(\STDERR, sprintf("::error::The packages of %s don't install together from Packagist.\n", $version));
    exit(1);
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
