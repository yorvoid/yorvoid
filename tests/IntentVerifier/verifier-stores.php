<?php

declare(strict_types=1);

/*
 * The verifier's file store for VerifierStoreConcurrencyTest: a state file for each test, removed when
 * the process that made it (the test's own) ends.
 */

use YorvoID\IntentVerifier\Storage\FileVerifierStore;
use YorvoID\IntentVerifier\VerifierStore;

return static function (string $type, string $implementation, string $test): object {
    if (VerifierStore::class !== $type || 'file' !== $implementation || 1 !== preg_match('/^[0-9a-f]{12}$/', $test)) {
        throw new InvalidArgumentException(sprintf('No %s store for %s.', $implementation, $type));
    }

    $path = sprintf('%s/yorvoid-verifier-race-%s', sys_get_temp_dir(), $test);

    if (!file_exists($path)) {
        touch($path);
        chmod($path, 0o600);
        register_shutdown_function(static fn (): bool => !file_exists($path) || unlink($path));
    }

    return new FileVerifierStore($path);
};
