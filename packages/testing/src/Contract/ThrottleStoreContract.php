<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Throttle\ThrottleStore;

/**
 * What every ThrottleStore keeps (PLT-025): YorvoID's own pass these tests, and a store of your own must too.
 * Use it in a test case of yours, with the stores to check:
 *
 * ```php
 * final class MyThrottleStoreTest extends TestCase
 * {
 *     use ThrottleStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function throttleStore(string $implementation): ThrottleStore
 *     {
 *         return new MyThrottleStore(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * @api
 */
trait ThrottleStoreContract
{
    /**
     * The store to check, empty: a new one for each test.
     */
    abstract protected function throttleStore(string $implementation): ThrottleStore;

    /**
     * The transactions the store takes part in: the same instance within a test.
     */
    abstract protected function transactions(string $implementation): Transactions;

    /**
     * An attempt counted inside a transaction outlives it: a rollback after a failed attempt would
     * otherwise undo its count, and with it the limit. A store may refuse to count inside one instead.
     */
    #[DataProvider('implementations')]
    public function testACountOutlivesATransactionThatFails(string $implementation): void
    {
        $store = $this->throttleStore($implementation);
        $transactions = $this->transactions($implementation);
        $failure = new \RuntimeException('The transaction fails.');
        $thrown = null;

        try {
            $transactions->run(static function () use ($store, $failure): never {
                $store->advance('a', self::NOW, 10, self::NOW + 20);

                throw $failure;
            });
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        if ($thrown === $failure) {
            self::assertSame(self::NOW + 10, $store->clearAt('a'), 'An attempt counted inside a transaction was undone with it: so would the limit be.');
        } else {
            self::assertNull($store->clearAt('a'), 'An attempt refused inside a transaction was counted.');
        }
    }

    /** Milliseconds in 2026: beyond 32 bits */
    private const int NOW = 1_793_210_400_000;

    #[DataProvider('implementations')]
    public function testAdvancesWhileTheKeyAllowsAnotherAttempt(string $implementation): void
    {
        $store = $this->throttleStore($implementation);
        self::assertNull($store->clearAt('a'));

        // An attempt adds 10 ms; one is allowed while the key clears at most 20 ms from now.
        self::assertSame(self::NOW + 10, $store->advance('a', self::NOW, 10, self::NOW + 20));
        self::assertSame(self::NOW + 20, $store->advance('a', self::NOW, 10, self::NOW + 20));
        self::assertSame(self::NOW + 30, $store->advance('a', self::NOW, 10, self::NOW + 20));
        self::assertNull($store->advance('a', self::NOW, 10, self::NOW + 20), 'An attempt over the limit was counted.');
        self::assertSame(self::NOW + 30, $store->clearAt('a'), 'A refused attempt changed the key.');

        // Later, from the key's clear time or from now, whichever is later.
        self::assertSame(self::NOW + 40, $store->advance('a', self::NOW + 15, 10, self::NOW + 35));
        self::assertSame(self::NOW + 110, $store->advance('a', self::NOW + 100, 10, self::NOW + 120));
        self::assertNull($store->clearAt('b'), 'Another key changed.');

        $store->remove('a');
        self::assertNull($store->clearAt('a'));
        self::assertSame(self::NOW + 7, $store->advance('a', self::NOW, 7, self::NOW));
        self::assertNull($store->advance('c', self::NOW, 7, self::NOW - 1), 'A new key was counted beyond its limit.');
        self::assertNull($store->clearAt('c'));
    }

    #[DataProvider('implementations')]
    public function testPurgesKeysClearAtOrBeforeTheMoment(string $implementation): void
    {
        $store = $this->throttleStore($implementation);
        $store->advance('before', self::NOW - 2, 1, self::NOW);
        $store->advance('at', self::NOW - 1, 1, self::NOW);
        $store->advance('after', self::NOW, 1, self::NOW);

        self::assertSame(2, $store->purge(self::NOW));
        self::assertSame([null, null, self::NOW + 1], [$store->clearAt('before'), $store->clearAt('at'), $store->clearAt('after')]);
    }
}
