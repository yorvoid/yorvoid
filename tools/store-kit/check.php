<?php

declare(strict_types=1);

/*
 * Runs the store kit as another project does: tools/store-kit/project is a library of its own stores
 * (on PDO, with no YorvoID store underneath), copied to var/store-kit, given YorvoID with Composer and
 * tested with yorvoid/testing's contracts. It sees only what the packages declare: a contract that needs
 * a package yorvoid/testing doesn't require, or a type the project can't reach, fails here.
 *
 * Until YorvoID is on Packagist, Composer installs it from this repository's packages, each at the
 * version main has, as on Packagist.
 *
 * Run: docker compose exec php php tools/store-kit/check.php
 */

$root = dirname(__DIR__, 2);
$work = $root.'/var/store-kit';

$run = static function (string $command) use ($work): void {
    echo "\n\$ {$command}\n";
    passthru(sprintf('cd %s && %s', escapeshellarg($work), $command), $status);

    if (0 !== $status) {
        fwrite(\STDERR, "\nThe store kit failed at: {$command}\n");
        exit(1);
    }
};

exec('rm -rf '.escapeshellarg($work));
exec(sprintf('cp -R %s %s', escapeshellarg(__DIR__.'/project'), escapeshellarg($work)), $output, $status);

if (0 !== $status) {
    fwrite(\STDERR, "The project couldn't be copied to {$work}.\n");
    exit(1);
}

putenv('COMPOSER_NO_INTERACTION=1');
$versions = [];
$files = glob($root.'/packages/*/composer.json');
assert(is_array($files));

foreach ($files as $file) {
    $package = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
    assert(is_array($package) && is_string($package['name']) && is_array($package['extra']) && is_array($package['extra']['branch-alias']) && is_string($package['extra']['branch-alias']['dev-main']));
    $versions[$package['name']] = $package['extra']['branch-alias']['dev-main'];
}

$repository = ['type' => 'path', 'url' => $root.'/packages/*', 'options' => ['versions' => $versions]];
$run('composer config repositories.yorvoid '.escapeshellarg(json_encode($repository, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)));
$run('composer config minimum-stability dev && composer config prefer-stable true');
$run('composer update --no-progress');
$run('vendor/bin/phpunit');
