<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Authn\Recovery\RecoveryCodeStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Testing\Ids;

/**
 * What every RecoveryCodeStore keeps (PLT-025): YorvoID's own pass these tests, and a store of your own must too.
 * Use it in a test case of yours, with the stores to check:
 *
 * ```php
 * final class MyRecoveryCodeStoreTest extends TestCase
 * {
 *     use RecoveryCodeStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function recoveryCodeStore(string $implementation): RecoveryCodeStore
 *     {
 *         return new MyRecoveryCodeStore(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * @api
 */
trait RecoveryCodeStoreContract
{
    /**
     * The store to check, empty: a new one for each test.
     */
    abstract protected function recoveryCodeStore(string $implementation): RecoveryCodeStore;

    /**
     * The transactions the store takes part in: the same instance within a test.
     */
    abstract protected function transactions(string $implementation): Transactions;

    public function testRecoveryCodeStoreRunsItsConcurrencyContract(): void
    {
        self::assertNull(Races::untested(static::class, RecoveryCodeStoreConcurrencyContract::class));
    }

    #[DataProvider('implementations')]
    public function testRecoveryCodeStoreTakesPartInTheTransactions(string $implementation): void
    {
        $store = $this->recoveryCodeStore($implementation);
        $transactions = $this->transactions($implementation);
        $acme = new Realm('acme');
        [$failed, $seen, $kept] = [new PrincipalId(Ids::next()), new PrincipalId(Ids::next()), new PrincipalId(Ids::next())];
        $at = new \DateTimeImmutable('2026-10-28 18:00:00 UTC');

        InTransaction::assertGoneAfterARollback($transactions, static fn () => $store->replace($acme, $failed, [self::hash('a')], $at), static fn (): bool => 0 === $store->count($acme, $failed));
        InTransaction::assertSeenInsideItsTransaction($transactions, static fn () => $store->replace($acme, $seen, [self::hash('b')], $at), static fn (): bool => 1 === $store->count($acme, $seen));
        InTransaction::assertTheTransactionGoesOn($transactions, [
            static fn (): bool => $store->use($acme, $kept, self::hash('c')),
            static fn (): bool => $store->use($acme, $seen, self::hash('a')),
        ], static fn () => $store->replace($acme, $kept, [self::hash('c')], $at), static fn (): bool => 1 === $store->count($acme, $kept));
    }

    #[DataProvider('implementations')]
    public function testACodeWorksOnceForItsPrincipal(string $implementation): void
    {
        $store = $this->recoveryCodeStore($implementation);
        $principal = new PrincipalId(Ids::next());
        $someoneElse = new PrincipalId(Ids::next());
        $store->replace(new Realm('acme'), $principal, [self::hash('a'), self::hash('b'), self::hash('a')], new \DateTimeImmutable('2026-10-28 18:00:00 UTC'));
        $store->replace(new Realm('acme'), $someoneElse, [self::hash('c')], new \DateTimeImmutable('2026-10-28 18:00:00 UTC'));
        self::assertSame(2, $store->count(new Realm('acme'), $principal));

        self::assertFalse($store->use(new Realm('acme'), $principal, self::hash('c')), 'Someone else\'s code worked.');
        self::assertFalse($store->use(new Realm('globex'), $principal, self::hash('a')), 'A code worked in another realm.');
        self::assertTrue($store->use(new Realm('acme'), $principal, self::hash('a')));
        self::assertFalse($store->use(new Realm('acme'), $principal, self::hash('a')), 'A code worked twice.');
        self::assertSame(1, $store->count(new Realm('acme'), $principal));
        self::assertSame(1, $store->count(new Realm('acme'), $someoneElse));
        self::assertSame(0, $store->count(new Realm('globex'), $principal));
    }

    #[DataProvider('implementations')]
    public function testReplacingRemovesTheOldCodes(string $implementation): void
    {
        $store = $this->recoveryCodeStore($implementation);
        $principal = new PrincipalId(Ids::next());
        $elsewhere = new PrincipalId(Ids::next());
        $store->replace(new Realm('acme'), $principal, [self::hash('a'), self::hash('b')], new \DateTimeImmutable('2026-10-28 18:00:00 UTC'));
        $store->replace(new Realm('globex'), $principal, [self::hash('a')], new \DateTimeImmutable('2026-10-28 18:00:00 UTC'));
        $store->replace(new Realm('acme'), $elsewhere, [self::hash('a')], new \DateTimeImmutable('2026-10-28 18:00:00 UTC'));

        $store->replace(new Realm('acme'), $principal, [self::hash('c')], new \DateTimeImmutable('2026-10-28 19:00:00 UTC'));

        self::assertFalse($store->use(new Realm('acme'), $principal, self::hash('a')), 'An old code still works.');
        self::assertTrue($store->use(new Realm('acme'), $principal, self::hash('c')));
        self::assertTrue($store->use(new Realm('globex'), $principal, self::hash('a')), 'Replacing reached another realm.');
        self::assertTrue($store->use(new Realm('acme'), $elsewhere, self::hash('a')), 'Replacing reached another principal.');

        $store->replace(new Realm('acme'), $principal, [], new \DateTimeImmutable('2026-10-28 20:00:00 UTC'));
        self::assertSame(0, $store->count(new Realm('acme'), $principal));
    }

    private static function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
