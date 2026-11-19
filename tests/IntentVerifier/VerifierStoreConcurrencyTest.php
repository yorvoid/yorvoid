<?php

declare(strict_types=1);

namespace YorvoID\Tests\IntentVerifier;

use PHPUnit\Framework\TestCase;
use YorvoID\Testing\Contract\VerifierStoreConcurrencyContract;

final class VerifierStoreConcurrencyTest extends TestCase
{
    use VerifierStoreConcurrencyContract;

    /**
     * @return iterable<string, array{string}>
     */
    public static function implementations(): iterable
    {
        yield 'file' => ['file'];
    }

    protected function storeFactory(): string
    {
        return __DIR__.'/verifier-stores.php';
    }
}
