<?php

declare(strict_types=1);

/*
 * The project's stores for yorvoid/testing's concurrency contracts: each test gets an SQLite file of its
 * own, made by the first process to ask (the test's own) and removed when that process ends.
 */

use Acme\Storage\PdoPrincipalStore;
use Acme\Storage\PdoThrottleStore;
use Acme\Storage\PdoTransactions;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Throttle\ThrottleStore;

return static function (string $type, string $implementation, string $test): object {
    /** @var array<string, PDO> $databases */
    static $databases = [];

    $pdo = $databases[$test] ??= (static function () use ($test): PDO {
        $path = sprintf('%s/acme-race-%s.sqlite', sys_get_temp_dir(), preg_replace('/[^0-9a-f]/', '', $test));
        $new = !is_file($path);
        $pdo = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);

        if ($new) {
            PdoPrincipalStore::createTable($pdo);
            PdoThrottleStore::createTable($pdo);
            register_shutdown_function(static fn (): bool => unlink($path));
        }

        return $pdo;
    })();

    return match ($type) {
        PrincipalStore::class => new PdoPrincipalStore($pdo),
        ThrottleStore::class => new PdoThrottleStore($pdo),
        Transactions::class => new PdoTransactions($pdo),
        default => throw new InvalidArgumentException(sprintf('No store for %s.', $type)),
    };
};
