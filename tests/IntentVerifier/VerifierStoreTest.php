<?php

declare(strict_types=1);

namespace YorvoID\Tests\IntentVerifier;

use PHPUnit\Framework\TestCase;
use YorvoID\IntentVerifier\Storage\FileVerifierLog;
use YorvoID\IntentVerifier\Storage\FileVerifierStore;
use YorvoID\IntentVerifier\Storage\InMemoryVerifierLog;
use YorvoID\IntentVerifier\Storage\InMemoryVerifierStore;
use YorvoID\IntentVerifier\VerifierLog;
use YorvoID\IntentVerifier\VerifierStore;
use YorvoID\Testing\Contract\ConcurrencyTestedIn;
use YorvoID\Testing\Contract\VerifierLogContract;
use YorvoID\Testing\Contract\VerifierStoreContract;

/**
 * The verifier's own stores pass the contracts; the one in memory only in its process.
 */
#[ConcurrencyTestedIn(VerifierStoreConcurrencyTest::class)]
final class VerifierStoreTest extends TestCase
{
    use VerifierLogContract;
    use VerifierStoreContract;

    private string $path;

    /**
     * @return iterable<string, array{string}>
     */
    public static function implementations(): iterable
    {
        yield 'in memory' => ['memory'];
        yield 'file' => ['file'];
    }

    protected function setUp(): void
    {
        $this->path = \sprintf('%s/yorvoid-verifier-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.log'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    protected function verifierStore(string $implementation): VerifierStore
    {
        return 'memory' === $implementation ? new InMemoryVerifierStore() : new FileVerifierStore($this->path);
    }

    protected function sameVerifierStore(string $implementation, VerifierStore $store): VerifierStore
    {
        return 'memory' === $implementation ? $store : new FileVerifierStore($this->path);
    }

    protected function verifierLog(string $implementation): VerifierLog
    {
        return 'memory' === $implementation ? new InMemoryVerifierLog() : new FileVerifierLog($this->path.'.log');
    }

    protected function sameVerifierLog(string $implementation, VerifierLog $log): VerifierLog
    {
        return 'memory' === $implementation ? $log : new FileVerifierLog($this->path.'.log');
    }
}
