<?php

declare(strict_types=1);

namespace YorvoID\Tests\IntentVerifier;

use PHPUnit\Framework\TestCase;
use YorvoID\IntentVerifier\CanonicalJson;
use YorvoID\IntentVerifier\Intent;
use YorvoID\IntentVerifier\PendingIntent;
use YorvoID\IntentVerifier\Storage\FileVerifierLog;
use YorvoID\IntentVerifier\Storage\FileVerifierStore;
use YorvoID\IntentVerifier\Storage\InMemoryVerifierLog;
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
        $valid = ['version' => 2, 'keys' => [], 'key_changes' => [], 'pending' => [], 'registrations' => [], 'bootstrap_secret_hash' => null, 'bootstrap_secret_expires_at' => null];
        $wrong = ['{', '5', (string) json_encode(['version' => 1] + $valid)];

        foreach (['keys' => 5, 'key_changes' => 5, 'pending' => 5, 'registrations' => 5, 'bootstrap_secret_hash' => 5, 'bootstrap_secret_expires_at' => 'soon'] as $field => $value) {
            $wrong[] = (string) json_encode([$field => $value] + $valid);
        }

        $wrong[] = (string) json_encode(['keys' => [['credential_id' => '*']]] + $valid);
        $wrong[] = (string) json_encode(['key_changes' => [['id' => 'a', 'kind' => 'replace']]] + $valid);

        foreach ($wrong as $contents) {
            file_put_contents($this->path, $contents);
            chmod($this->path, 0o600);

            try {
                new FileVerifierStore($this->path)->transaction(static fn (VerifierState $state): null => null);
                self::fail(\sprintf('The state %s was read.', $contents));
            } catch (StorageError $exception) {
                self::assertSame('The verifier\'s state isn\'t what the verifier wrote.', $exception->getMessage(), $contents);
            }
        }

        file_put_contents($this->path, (string) json_encode($valid));
        self::assertSame([], new FileVerifierStore($this->path)->transaction(static fn (VerifierState $state): array => $state->keys));
    }

    /**
     * The state decides which keys sign: a file others can write, or a link to one, isn't trusted.
     */
    public function testRefusesAFileThatIsntPrivateToIt(): void
    {
        $store = new FileVerifierStore($this->path);
        $store->transaction(static fn (VerifierState $state): null => null);

        chmod($this->path, 0o640);
        $this->refusesTheFile($store, 'can be read or written by other users: chmod 600 it.');
        chmod($this->path, 0o602);
        $this->refusesTheFile($store, 'can be read or written by other users');
        chmod($this->path, 0o600);

        $link = $this->path.'-link';
        symlink($this->path, $link);

        try {
            $this->refusesTheFile(new FileVerifierStore($link), 'is a symbolic link: give the file itself.');
            $this->refusesTheFile(new FileVerifierLog($link), 'is a symbolic link');
        } finally {
            unlink($link);
        }

        $directory = $this->path.'-directory';
        mkdir($directory, 0o700);

        try {
            $this->refusesTheFile(new FileVerifierStore($directory), 'can\'t be');
        } finally {
            rmdir($directory);
        }
    }

    public function testRefusesAStateItCantOpen(): void
    {
        $this->expectException(StorageError::class);
        $this->expectExceptionMessage('can\'t be opened');

        new FileVerifierStore('/nonexistent/directory/state.json')->transaction(static fn (VerifierState $state): null => null);
    }

    private function refusesTheFile(FileVerifierStore|FileVerifierLog $file, string $message): void
    {
        try {
            $file instanceof FileVerifierStore ? $file->transaction(static fn (VerifierState $state): null => null) : $file->record('test', [], new \DateTimeImmutable());
            self::fail('The file was used: '.$message);
        } catch (StorageError $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    /**
     * An entry is appended after reading only the last line: entries longer than a read's block, and
     * many of them, chain all the same.
     */
    public function testTheLogContinuesFromItsLastLineHoweverLong(): void
    {
        $log = new FileVerifierLog($this->path);
        $at = new \DateTimeImmutable('@1793210400');

        foreach ([10, 5000, 3, 9000, 4100, 1] as $i => $length) {
            $log->record('prepared', ['nonce' => str_repeat((string) $i, $length)], $at);
        }

        self::assertTrue($log->isIntact());
        $lines = file($this->path, \FILE_IGNORE_NEW_LINES);
        \assert(\is_array($lines) && 6 === \count($lines));

        for ($i = 1; $i < 6; ++$i) {
            $before = json_decode($lines[$i - 1], true);
            $entry = json_decode($lines[$i], true);
            \assert(\is_array($before) && \is_array($entry));
            self::assertSame($before['hash'], $entry['previous'], \sprintf('Entry %d doesn\'t hold the one before.', $i));
        }
    }

    /**
     * Read back from the end, block by block, as far as the time asked for, and into the archive of a
     * rotation since.
     */
    public function testTheLogTellsANonceUsedSinceATime(): void
    {
        $log = new FileVerifierLog($this->path);
        $archive = $this->path.'-1.jsonl';

        try {
            $log->record('prepared', ['nonce' => 'early'], new \DateTimeImmutable('@1793210000'));
            $log->record('verified', ['nonce' => 'early'], new \DateTimeImmutable('@1793210010'));
            $log->record('prepared', ['nonce' => 'a'], new \DateTimeImmutable('@1793210400'));
            $log->record('verified', ['nonce' => 'a', 'padding' => str_repeat('x', 9000)], new \DateTimeImmutable('@1793210460'));
            $log->rotate($archive, new \DateTimeImmutable('@1793210500'));
            $log->record('prepared', ['nonce' => 'b'], new \DateTimeImmutable('@1793210560'));

            foreach ([0, 1, 7] as $i) {
                $log->record('refused', ['nonce' => 'c'.$i, 'padding' => str_repeat('y', 3000 * $i)], new \DateTimeImmutable('@1793210570'));
            }

            self::assertTrue($log->usedNonce('a', new \DateTimeImmutable('@1793210400')), 'The archive wasn\'t read.');
            self::assertTrue($log->usedNonce('c1', new \DateTimeImmutable('@1793210400')));
            self::assertTrue($log->usedNonce('c7', new \DateTimeImmutable('@1793210570')));
            self::assertFalse($log->usedNonce('b', new \DateTimeImmutable('@1793210400')), 'Preparing a nonce used it.');
            self::assertFalse($log->usedNonce('early', new \DateTimeImmutable('@1793210400')), 'The log was read before the time asked for.');
            self::assertTrue($log->usedNonce('early', new \DateTimeImmutable('@1793210000')));
            self::assertFalse(new FileVerifierLog($this->path.'-none')->usedNonce('a', new \DateTimeImmutable('@1793210000')));

            unlink($archive);
            self::assertFalse($log->usedNonce('a', new \DateTimeImmutable('@1793210400')), 'A missing archive isn\'t read.');
        } finally {
            foreach ([$archive, $this->path.'-none'] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function testTheLogInMemoryTellsANonceUsedSinceATime(): void
    {
        $log = new InMemoryVerifierLog();
        $log->record('prepared', ['nonce' => 'a'], new \DateTimeImmutable('@100'));
        $log->record('verified', ['nonce' => 'a'], new \DateTimeImmutable('@200'));
        $log->record('prepared', ['nonce' => 'b'], new \DateTimeImmutable('@200'));

        self::assertTrue($log->usedNonce('a', new \DateTimeImmutable('@200')));
        self::assertFalse($log->usedNonce('a', new \DateTimeImmutable('@201')));
        self::assertFalse($log->usedNonce('b', new \DateTimeImmutable('@100')), 'Preparing a nonce used it.');
        self::assertFalse($log->usedNonce('c', new \DateTimeImmutable('@100')));
    }

    public function testRefusesALogItDidntWriteWhenAskedForANonce(): void
    {
        file_put_contents($this->path, "not json\n");

        $this->expectException(StorageError::class);

        new FileVerifierLog($this->path)->usedNonce('a', new \DateTimeImmutable('@0'));
    }

    public function testARotatedLogGoesOnFromItsArchive(): void
    {
        $log = new FileVerifierLog($this->path);
        $archive = $this->path.'-1.jsonl';
        $log->record('prepared', ['nonce' => 'a'], new \DateTimeImmutable('@1793210400'));
        $log->record('verified', ['nonce' => 'a'], new \DateTimeImmutable('@1793210460'));
        $archived = (string) file_get_contents($this->path);
        $lines = explode("\n", trim($archived));
        $last = json_decode($lines[1], true);
        \assert(\is_array($last));

        try {
            $log->rotate($archive, new \DateTimeImmutable('@1793210500'));
            $log->record('prepared', ['nonce' => 'b'], new \DateTimeImmutable('@1793210560'));

            self::assertSame($archived, file_get_contents($archive), 'The archive isn\'t the log as it was.');
            self::assertSame(0o600, fileperms($archive) & 0o777);
            self::assertTrue(new FileVerifierLog($archive)->isIntact());
            self::assertTrue($log->isIntact());
            $rotated = file($this->path, \FILE_IGNORE_NEW_LINES);
            \assert(\is_array($rotated) && 2 === \count($rotated));
            $first = json_decode($rotated[0], true);
            \assert(\is_array($first));
            self::assertSame([FileVerifierLog::ROTATED, ['archive' => basename($archive)], $last['hash'], 1793210500], [$first['event'], $first['details'], $first['previous'], $first['at']]);

            // Only the first entry may link to an archive.
            file_put_contents($this->path, $rotated[1]."\n");
            self::assertFalse($log->isIntact(), 'A log began in the middle.');
            file_put_contents($this->path, $rotated[0]."\n".$rotated[0]."\n");
            self::assertFalse($log->isIntact(), 'A second rotation entry linked to nothing before it.');
            $forged = json_decode($rotated[0], true);
            \assert(\is_array($forged));
            $forged['event'] = 'prepared';
            file_put_contents($this->path, CanonicalJson::encode($forged)."\n");
            self::assertFalse($log->isIntact());

            try {
                $log->rotate($archive, new \DateTimeImmutable('@1793210600'));
                self::fail('An archive was written over.');
            } catch (StorageError $exception) {
                self::assertStringContainsString('exists already, or can\'t be created', $exception->getMessage());
            }
        } finally {
            is_file($archive) && unlink($archive);
        }
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
