<?php

declare(strict_types=1);

namespace Acme\Storage\Tests;

use PHPUnit\Framework\TestCase;
use YorvoID\Testing\Contract\PrincipalStoreConcurrencyContract;
use YorvoID\Testing\Contract\ThrottleStoreConcurrencyContract;

final class PdoStoreConcurrencyTest extends TestCase
{
    use PrincipalStoreConcurrencyContract;
    use ThrottleStoreConcurrencyContract;

    /**
     * @return iterable<string, array{string}>
     */
    public static function implementations(): iterable
    {
        yield 'PDO on an SQLite file' => ['sqlite'];
    }

    protected function storeFactory(): string
    {
        return __DIR__.'/race-stores.php';
    }
}
