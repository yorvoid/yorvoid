<?php

declare(strict_types=1);

/*
 * Moves this checkout's dependencies to Symfony 7.4 LTS, the oldest Symfony YorvoID supports, to run
 * the checks on it: php tools/compat/symfony-lts.php, then composer test. It changes composer.lock
 * and vendor/: CI does it in a copy of its own; locally, git checkout composer.lock && composer install.
 *
 * Every Symfony component goes to 7.4, except symfony/var-dumper, which a development tool
 * (innmind/black-box) needs at 8 and YorvoID only suggests.
 */

$lock = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.lock'), true, 512, \JSON_THROW_ON_ERROR);
$with = [];

foreach (['packages', 'packages-dev'] as $section) {
    foreach (is_array($lock) && is_array($lock[$section] ?? null) ? $lock[$section] : [] as $package) {
        $name = is_array($package) ? ($package['name'] ?? null) : null;
        $version = is_array($package) ? ($package['version'] ?? null) : null;

        if (is_string($name) && is_string($version) && str_starts_with($name, 'symfony/') && str_starts_with($version, 'v8.') && 'symfony/var-dumper' !== $name) {
            $with[] = escapeshellarg('--with='.$name.':7.4.*');
        }
    }
}

passthru('composer update --with-all-dependencies --no-interaction --no-progress '.implode(' ', $with), $status);
exit($status);
