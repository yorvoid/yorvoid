<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Policy\Attribute\AttributeStore;

/**
 * What every AttributeStore keeps (PLT-025): YorvoID's own pass these tests, and a store of your own must too.
 * Use it in a test case of yours, with the stores to check:
 *
 * ```php
 * final class MyAttributeStoreTest extends TestCase
 * {
 *     use AttributeStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function attributeStore(string $implementation): AttributeStore
 *     {
 *         return new MyAttributeStore(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * @api
 */
trait AttributeStoreContract
{
    /**
     * The store to check, empty: a new one for each test.
     */
    abstract protected function attributeStore(string $implementation): AttributeStore;

    /**
     * The transactions the store takes part in: the same instance within a test.
     */
    abstract protected function transactions(string $implementation): Transactions;

    #[DataProvider('implementations')]
    public function testAttributeStoreTakesPartInTheTransactions(string $implementation): void
    {
        $store = $this->attributeStore($implementation);
        $transactions = $this->transactions($implementation);
        $acme = new Realm('acme');
        $ada = new PrincipalId(new UuidV7());

        InTransaction::assertGoneAfterARollback($transactions, static fn () => $store->set($acme, $ada, 'acme.failed', 1), static fn (): bool => !\array_key_exists('acme.failed', $store->of($acme, $ada)));
        InTransaction::assertSeenInsideItsTransaction($transactions, static fn () => $store->set($acme, $ada, 'acme.seen', 1), static fn (): bool => \array_key_exists('acme.seen', $store->of($acme, $ada)));
        InTransaction::assertTheTransactionGoesOn($transactions, [
            static fn (): bool => $store->remove($acme, $ada, 'acme.none'),
            static fn () => $store->forget($acme, new PrincipalId(new UuidV7())),
        ], static fn () => $store->set($acme, $ada, 'acme.kept', 1), static fn (): bool => \array_key_exists('acme.kept', $store->of($acme, $ada)));
    }

    #[DataProvider('implementations')]
    public function testKeepsEachPrincipalsAttributesWithTheirTypes(string $implementation): void
    {
        $store = $this->attributeStore($implementation);
        $acme = new Realm('acme');
        $ada = new PrincipalId(new UuidV7());
        $bob = new PrincipalId(new UuidV7());

        self::assertSame([], $store->of($acme, $ada));

        $store->set($acme, $ada, 'acme.teams', ['blue', 'grün']);
        $store->set($acme, $ada, 'acme.department', 'R&D');
        $store->set($acme, $ada, 'acme.clearance', 3);
        $store->set($acme, $ada, 'acme.contractor', false);
        $store->set($acme, $ada, 'acme.none', []);
        $store->set($acme, $bob, 'acme.department', 'Sales');
        $store->set(new Realm('other'), $ada, 'acme.department', 'Elsewhere');
        $store->set($acme, $ada, 'acme.clearance', 4);

        self::assertSame(['acme.clearance' => 4, 'acme.contractor' => false, 'acme.department' => 'R&D', 'acme.none' => [], 'acme.teams' => ['blue', 'grün']], $store->of($acme, $ada), 'By name; setting again replaces.');

        self::assertTrue($store->remove($acme, $ada, 'acme.contractor'));
        self::assertFalse($store->remove($acme, $ada, 'acme.contractor'));
        self::assertArrayNotHasKey('acme.contractor', $store->of($acme, $ada));

        $store->forget($acme, $ada);
        self::assertSame([], $store->of($acme, $ada));
        self::assertSame(['acme.department' => 'Sales'], $store->of($acme, $bob));
        self::assertSame(['acme.department' => 'Elsewhere'], $store->of(new Realm('other'), $ada));
    }
}
