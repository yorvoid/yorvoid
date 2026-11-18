<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\IntentVerifier\VerifierState;
use YorvoID\IntentVerifier\VerifierStore;

/**
 * What every VerifierStore keeps (INT-007, INT-009): what the work changed is kept when it returns, and
 * nothing when it throws, also for another instance over the same state. VerifierStoreConcurrencyContract
 * adds that no process's change is lost.
 *
 * Use FileVerifierStore. One of your own must pass this, and keep the state out of the console's reach:
 * the contract can't test that, so that one is yours to keep.
 *
 * ```php
 * #[ConcurrencyTestedIn(MyVerifierStoreConcurrencyTest::class)]
 * final class MyVerifierStoreTest extends TestCase
 * {
 *     use VerifierStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function verifierStore(string $implementation): VerifierStore
 *     {
 *         return new MyVerifierStore(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * Needs yorvoid/intent-verifier, which yorvoid/testing suggests.
 *
 * @api
 */
trait VerifierStoreContract
{
    /**
     * The store to check, empty: a new one for each test.
     */
    abstract protected function verifierStore(string $implementation): VerifierStore;

    /**
     * Another instance over the same state, as another process would have: the same one unless overridden.
     */
    protected function sameVerifierStore(string $implementation, VerifierStore $store): VerifierStore
    {
        return $store;
    }

    public function testVerifierStoreRunsItsConcurrencyContract(): void
    {
        self::assertNull(Races::untested(static::class, VerifierStoreConcurrencyContract::class));
    }

    #[DataProvider('implementations')]
    public function testVerifierStoreKeepsWhatAFinishedTransactionChanged(string $implementation): void
    {
        $store = $this->verifierStore($implementation);
        $expires = new \DateTimeImmutable('@1793210400');

        $done = new \ArrayObject();
        self::assertSame($done, $store->transaction(static function (VerifierState $state) use ($expires, $done): \ArrayObject {
            $state->bootstrapSecretHash = str_repeat('a', 64);
            $state->bootstrapSecretExpiresAt = $expires;

            return $done;
        }), 'The transaction didn\'t return what its work did.');

        foreach ([$store, $this->sameVerifierStore($implementation, $store)] as $instance) {
            $instance->transaction(static function (VerifierState $state) use ($expires): void {
                self::assertSame(str_repeat('a', 64), $state->bootstrapSecretHash, 'A finished transaction\'s change wasn\'t kept.');
                self::assertEquals($expires, $state->bootstrapSecretExpiresAt);
            });
        }
    }

    #[DataProvider('implementations')]
    public function testVerifierStoreKeepsNothingOfAFailedTransaction(string $implementation): void
    {
        $store = $this->verifierStore($implementation);
        $store->transaction(static function (VerifierState $state): void {
            $state->bootstrapSecretHash = str_repeat('a', 64);
        });

        try {
            $store->transaction(static function (VerifierState $state): never {
                $state->bootstrapSecretHash = str_repeat('b', 64);

                throw new \RuntimeException('The work fails.');
            });
        } catch (\RuntimeException) {
            // As it should.
        }

        foreach ([$store, $this->sameVerifierStore($implementation, $store)] as $instance) {
            $instance->transaction(static function (VerifierState $state): void {
                self::assertSame(str_repeat('a', 64), $state->bootstrapSecretHash, 'A failed transaction\'s change was kept.');
            });
        }
    }
}
