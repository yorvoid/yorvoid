<?php

declare(strict_types=1);

namespace YorvoID\Tests\IntentVerifier;

use PHPUnit\Framework\TestCase;
use YorvoID\IntentVerifier\Intent;
use YorvoID\IntentVerifier\PendingIntent;
use YorvoID\IntentVerifier\Storage\FileVerifierLog;
use YorvoID\IntentVerifier\Storage\FileVerifierStore;
use YorvoID\IntentVerifier\Storage\InMemoryVerifierStore;
use YorvoID\IntentVerifier\Storage\StorageError;
use YorvoID\IntentVerifier\VerifierState;

/**
 * The verifier's own files: its state, and its hash-chained log (INT-007, INT-009, INT-010).
 */
final class StorageTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = \sprintf('%s/yorvoid-verifier-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
    }

    public function testTheStateFileIsTheOwnersAloneAndKeepsWhatWasSaved(): void
    {
        $store = new FileVerifierStore($this->path);
        $intent = new Intent('acme.site.remove', ['site' => 'jane', 'force' => false, 'days' => 30], 'acme', 'nonce', new \DateTimeImmutable('@1793210400'), new \DateTimeImmutable('@1793210700'));

        $store->transaction(static function (VerifierState $state) use ($intent): void {
            $state->pending['nonce'] = new PendingIntent($intent, true);
            $state->bootstrapSecretHash = 'abc';
        });

        self::assertSame(0o600, fileperms($this->path) & 0o777);
        $kept = new FileVerifierStore($this->path)->transaction(static fn (VerifierState $state): VerifierState => $state);
        self::assertEquals(['nonce' => new PendingIntent($intent, true)], $kept->pending);
        self::assertSame('abc', $kept->bootstrapSecretHash);
    }

    public function testATransactionHoldsTheFileAgainstOthers(): void
    {
        $path = $this->path;
        $taken = new FileVerifierStore($path)->transaction(static function (VerifierState $state) use ($path): bool {
            $other = fopen($path, 'r');
            \assert(false !== $other);

            return !flock($other, \LOCK_EX | \LOCK_NB);
        });

        self::assertTrue($taken, 'Another process could take the file during a transaction.');
        $other = fopen($path, 'r');
        \assert(false !== $other);
        self::assertTrue(flock($other, \LOCK_EX | \LOCK_NB), 'The file stayed locked after the transaction.');
    }

    public function testAFailedTransactionChangesNothing(): void
    {
        $store = new FileVerifierStore($this->path);
        $store->transaction(static function (VerifierState $state): void {
            $state->bootstrapSecretHash = 'kept';
        });

        try {
            $store->transaction(static function (VerifierState $state): never {
                $state->bootstrapSecretHash = 'changed';

                throw new \RuntimeException('failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame('kept', $store->transaction(static fn (VerifierState $state): ?string => $state->bootstrapSecretHash));
    }

    public function testAFailedTransactionInMemoryChangesNothingEither(): void
    {
        $store = new InMemoryVerifierStore();

        try {
            $store->transaction(static function (VerifierState $state): never {
                $state->bootstrapSecretHash = 'changed';

                throw new \RuntimeException('failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertNull($store->transaction(static fn (VerifierState $state): ?string => $state->bootstrapSecretHash));
    }

    public function testWritesAShorterStateOverALongerOne(): void
    {
        $store = new FileVerifierStore($this->path);
        $store->transaction(static function (VerifierState $state): void {
            $state->bootstrapSecretHash = str_repeat('a', 500);
        });
        $store->transaction(static function (VerifierState $state): void {
            $state->bootstrapSecretHash = null;
        });

        self::assertNull($store->transaction(static fn (VerifierState $state): ?string => $state->bootstrapSecretHash));
    }

    public function testRefusesAStateItDidntWrite(): void
    {
        foreach (['{', '5', '{"version": 2, "keys": [], "pending": []}', '{"version": 1, "keys": 5, "pending": []}', '{"version": 1, "keys": [], "pending": 5}', '{"version": 1, "keys": [{"credential_id": "*"}], "pending": []}', '{"version": 1, "keys": [], "pending": [], "bootstrap_secret_hash": 5}'] as $contents) {
            file_put_contents($this->path, $contents);

            try {
                new FileVerifierStore($this->path)->transaction(static fn (VerifierState $state): null => null);
                self::fail(\sprintf('The state %s was read.', $contents));
            } catch (StorageError $exception) {
                self::assertSame('The verifier\'s state isn\'t what the verifier wrote.', $exception->getMessage());
            }
        }
    }

    public function testRefusesAStateItCantOpen(): void
    {
        $this->expectException(StorageError::class);
        $this->expectExceptionMessage('can\'t be opened');

        new FileVerifierStore('/nonexistent/directory/state.json')->transaction(static fn (VerifierState $state): null => null);
    }

    public function testTheLogIsHashChained(): void
    {
        $log = new FileVerifierLog($this->path);
        self::assertTrue($log->isIntact(), 'An empty log isn\'t intact.');

        $log->record('prepared', ['action' => 'acme.site.remove', 'nonce' => 'a'], new \DateTimeImmutable('@1793210400'));
        self::assertMatchesRegularExpression('/^\{"at":1793210400,"details":\{"action":"acme\.site\.remove","nonce":"a"\},"event":"prepared","hash":"[0-9a-f]{64}","previous":"0{64}"\}\n$/D', (string) file_get_contents($this->path));
        $log->record('verified', ['action' => 'acme.site.remove', 'key' => 'k', 'algorithm' => -7], new \DateTimeImmutable('@1793210460'));
        $log->record('bootstrap_secret_created', [], new \DateTimeImmutable('@1793210500'));
        self::assertTrue($log->isIntact());

        $lines = file($this->path, \FILE_IGNORE_NEW_LINES);
        \assert(\is_array($lines) && 3 === \count($lines));
        $first = json_decode($lines[0], true);
        \assert(\is_array($first));
        self::assertSame(str_repeat('0', 64), $first['previous']);
        $second = json_decode($lines[1], true);
        \assert(\is_array($second));
        self::assertSame($first['hash'], $second['previous']);

        foreach ([
            'a changed entry' => [str_replace('"verified"', '"refused"', $lines[1]), $lines[0], $lines[2]],
            'a removed entry' => [$lines[0], $lines[2]],
            'reordered entries' => [$lines[1], $lines[0], $lines[2]],
            'a changed hash' => [$lines[0], $lines[1], preg_replace('/"hash":"[0-9a-f]{4}/', '"hash":"0000', $lines[2])],
            'reformatted' => [str_replace(',', ', ', $lines[0]), $lines[1], $lines[2]],
            'a hash that isn\'t text' => ['{"previous":"'.str_repeat('0', 64).'","hash":5}'],
            'not an entry' => ['5'],
        ] as $tampering => $tampered) {
            file_put_contents($this->path, implode("\n", $tampered)."\n");
            self::assertFalse($log->isIntact(), \sprintf('The log looked intact with %s.', $tampering));
        }

        foreach (["not json\n", "{\"no\":\"hash\"}\n", "{\"hash\":5}\n"] as $last) {
            file_put_contents($this->path, $last);

            try {
                $log->record('prepared', [], new \DateTimeImmutable('@1793210400'));
                self::fail(\sprintf('The log was continued after %s', $last));
            } catch (StorageError $exception) {
                self::assertSame('The verifier\'s log isn\'t what the verifier wrote.', $exception->getMessage());
            }
        }
    }
}
