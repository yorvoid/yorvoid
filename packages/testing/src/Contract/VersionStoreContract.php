<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Authorization\VersionStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Transactions;

/**
 * What every VersionStore keeps: YorvoID's own pass these tests, and a store of your own must too.
 * Use it in a test case of yours, with the stores to check:
 *
 * ```php
 * final class MyVersionStoreTest extends TestCase
 * {
 *     use VersionStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function versionStore(string $implementation): VersionStore
 *     {
 *         return new MyVersionStore(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * @api
 */
trait VersionStoreContract
{
    /**
     * The store to check, empty: a new one for each test.
     */
    abstract protected function versionStore(string $implementation): VersionStore;

    /**
     * The transactions the store takes part in: the same instance within a test.
     */
    abstract protected function transactions(string $implementation): Transactions;

    #[DataProvider('implementations')]
    public function testASourceNeverBumpedIsAtZero(string $implementation): void
    {
        $store = $this->versionStore($implementation);

        self::assertSame(['policy' => 0, 'relations' => 0], $store->versions(new Realm('acme'), ['policy', 'relations']));
        self::assertSame([], $store->versions(new Realm('acme'), []), 'Nothing asked, nothing read.');
    }

    /**
     * Each realm and each source has its own counter, and every one asked is read at once.
     */
    #[DataProvider('implementations')]
    public function testEachRealmAndSourceCountsOnItsOwn(string $implementation): void
    {
        $store = $this->versionStore($implementation);
        $acme = new Realm('acme');
        $beta = new Realm('beta');
        $transactions = $this->transactions($implementation);
        // A change of authority takes its realm's lock first, then bumps.
        $transactions->run(static function () use ($transactions, $store, $acme): void {
            $transactions->lockRealm($acme);
            self::assertSame(1, $store->bump($acme, 'policy'));
            self::assertSame(2, $store->bump($acme, 'policy'));
            self::assertSame(1, $store->bump($acme, 'relations'));
        });
        $transactions->run(static function () use ($transactions, $store, $beta): void {
            $transactions->lockRealm($beta);
            self::assertSame(1, $store->bump($beta, 'policy'));
        });

        self::assertSame(['policy' => 2, 'relations' => 1, 'delegation' => 0], $store->versions($acme, ['policy', 'relations', 'delegation']));
        self::assertSame(['relations' => 0, 'policy' => 1], $store->versions($beta, ['relations', 'policy']), 'In the order asked.');
    }

    /**
     * A bump is part of the change's transaction: seen in it, and gone with it if it fails.
     */
    #[DataProvider('implementations')]
    public function testABumpIsPartOfItsTransaction(string $implementation): void
    {
        $store = $this->versionStore($implementation);
        $acme = new Realm('acme');
        $failure = new \RuntimeException('The change fails.');
        $transactions = $this->transactions($implementation);

        try {
            $transactions->run(static function () use ($transactions, $store, $acme, $failure): never {
                $transactions->lockRealm($acme);
                $store->bump($acme, 'policy');
                self::assertSame(['policy' => 1], $store->versions($acme, ['policy']), 'Seen in its own transaction.');

                throw $failure;
            });
        } catch (\RuntimeException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame(['policy' => 0], $store->versions($acme, ['policy']), 'Gone with the transaction.');
    }
}
