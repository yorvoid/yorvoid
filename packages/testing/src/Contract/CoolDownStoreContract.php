<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\StepUp\CoolDownStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Transactions;

/**
 * What every CoolDownStore keeps (PLT-025): YorvoID's own pass these tests, and a store of your own must too.
 * Use it in a test case of yours, with the stores to check:
 *
 * ```php
 * final class MyCoolDownStoreTest extends TestCase
 * {
 *     use CoolDownStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function coolDownStore(string $implementation): CoolDownStore
 *     {
 *         return new MyCoolDownStore(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * @api
 */
trait CoolDownStoreContract
{
    /**
     * The store to check, empty: a new one for each test.
     */
    abstract protected function coolDownStore(string $implementation): CoolDownStore;

    /**
     * The transactions the store takes part in: the same instance within a test.
     */
    abstract protected function transactions(string $implementation): Transactions;

    #[DataProvider('implementations')]
    public function testCoolDownStoreTakesPartInTheTransactions(string $implementation): void
    {
        $store = $this->coolDownStore($implementation);
        $transactions = $this->transactions($implementation);
        $acme = new Realm('acme');
        [$failed, $seen, $kept] = [new PrincipalId(new UuidV7()), new PrincipalId(new UuidV7()), new PrincipalId(new UuidV7())];
        $until = new \DateTimeImmutable('2026-10-28 18:00:00 UTC');

        InTransaction::assertGoneAfterARollback($transactions, static fn () => $store->extend($acme, $failed, $until), static fn (): bool => null === $store->until($acme, $failed));
        InTransaction::assertSeenInsideItsTransaction($transactions, static fn () => $store->extend($acme, $seen, $until), static fn (): bool => null !== $store->until($acme, $seen));
        InTransaction::assertTheTransactionGoesOn($transactions, [
            static fn (): int => $store->purge(new \DateTimeImmutable('2000-01-01 00:00:00 UTC')),
        ], static fn () => $store->extend($acme, $kept, $until), static fn (): bool => null !== $store->until($acme, $kept));
    }

    #[DataProvider('implementations')]
    public function testACoolDownOnlyGrowsLonger(string $implementation): void
    {
        $store = $this->coolDownStore($implementation);
        $principal = new PrincipalId(new UuidV7());
        $someoneElse = new PrincipalId(new UuidV7());
        self::assertNull($store->until(new Realm('acme'), $principal));

        $store->extend(new Realm('acme'), $principal, new \DateTimeImmutable('2026-10-28 18:00:00 UTC'));
        $store->extend(new Realm('acme'), $someoneElse, new \DateTimeImmutable('2026-10-30 18:00:00 UTC'));
        $store->extend(new Realm('globex'), $principal, new \DateTimeImmutable('2026-10-31 18:00:00 UTC'));
        self::assertEquals(new \DateTimeImmutable('2026-10-28 18:00:00 UTC'), $store->until(new Realm('acme'), $principal));

        $store->extend(new Realm('acme'), $principal, new \DateTimeImmutable('2026-10-29 18:00:00 UTC'));
        self::assertEquals(new \DateTimeImmutable('2026-10-29 18:00:00 UTC'), $store->until(new Realm('acme'), $principal));

        $store->extend(new Realm('acme'), $principal, new \DateTimeImmutable('2026-10-28 20:00:00 UTC'));
        self::assertEquals(new \DateTimeImmutable('2026-10-29 18:00:00 UTC'), $store->until(new Realm('acme'), $principal), 'A shorter cool-down cut a longer one short.');
        self::assertEquals(new \DateTimeImmutable('2026-10-30 18:00:00 UTC'), $store->until(new Realm('acme'), $someoneElse));
        self::assertEquals(new \DateTimeImmutable('2026-10-31 18:00:00 UTC'), $store->until(new Realm('globex'), $principal));
    }

    #[DataProvider('implementations')]
    public function testPurgesOnlyWhatEndedBefore(string $implementation): void
    {
        $store = $this->coolDownStore($implementation);
        $ended = new PrincipalId(new UuidV7());
        $endingNow = new PrincipalId(new UuidV7());
        $store->extend(new Realm('acme'), $ended, new \DateTimeImmutable('2026-10-28 17:59:59 UTC'));
        $store->extend(new Realm('globex'), $endingNow, new \DateTimeImmutable('2026-10-28 18:00:00 UTC'));

        self::assertSame(1, $store->purge(new \DateTimeImmutable('2026-10-28 18:00:00 UTC')));
        self::assertNull($store->until(new Realm('acme'), $ended));
        self::assertNotNull($store->until(new Realm('globex'), $endingNow));
    }
}
