<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StorageException;

/**
 * What every PrincipalStore keeps (PLT-025): YorvoID's own pass these tests, and a store of your own must too.
 * Use it in a test case of yours, with the stores to check:
 *
 * ```php
 * final class MyPrincipalStoreTest extends TestCase
 * {
 *     use PrincipalStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function principalStore(string $implementation): PrincipalStore
 *     {
 *         return new MyPrincipalStore(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * @api
 */
trait PrincipalStoreContract
{
    /**
     * The store to check, empty: a new one for each test.
     */
    abstract protected function principalStore(string $implementation): PrincipalStore;

    #[DataProvider('implementations')]
    public function testKeepsEveryField(string $implementation): void
    {
        $store = $this->principalStore($implementation);

        foreach (PrincipalKind::cases() as $i => $kind) {
            $status = PrincipalStatus::cases()[$i % \count(PrincipalStatus::cases())];
            $principal = new Principal(new PrincipalId(new UuidV7()), new Realm('acme'), $kind, $status, new \DateTimeImmutable('2026-10-27 18:40:01 UTC'), new \DateTimeImmutable('2026-10-28 09:15:30 UTC'), new \DateTimeImmutable('2026-10-28 09:15:29 UTC'), 3 + $i);
            $store->add($principal);

            self::assertEquals($principal, $store->get(new Realm('acme'), $principal->id));
        }
    }

    #[DataProvider('implementations')]
    public function testFindsNothingUnknownOrInAnotherRealm(string $implementation): void
    {
        $store = $this->principalStore($implementation);
        $principal = self::principal(new Realm('acme'));
        $store->add($principal);

        self::assertNull($store->get(new Realm('acme'), new PrincipalId(new UuidV7())));
        self::assertNull($store->get(new Realm('globex'), $principal->id), 'A principal was found across realms.');
    }

    #[DataProvider('implementations')]
    public function testKnowsWhetherARealmHasAnActivePrincipal(string $implementation): void
    {
        $store = $this->principalStore($implementation);
        self::assertFalse($store->hasActive(new Realm('acme')));

        foreach ([PrincipalStatus::Invited, PrincipalStatus::Suspended, PrincipalStatus::Deleted] as $status) {
            $store->add(new Principal(new PrincipalId(new UuidV7()), new Realm('acme'), PrincipalKind::Human, $status, new \DateTimeImmutable(), new \DateTimeImmutable(), new \DateTimeImmutable()));
        }

        $store->add(self::principal(new Realm('globex')));
        self::assertFalse($store->hasActive(new Realm('acme')), 'Only an active principal of the realm counts.');
        $store->add(self::principal(new Realm('acme')));
        self::assertTrue($store->hasActive(new Realm('acme')));
    }

    #[DataProvider('implementations')]
    public function testRefusesADuplicateIdEvenInAnotherRealm(string $implementation): void
    {
        $store = $this->principalStore($implementation);
        $principal = self::principal(new Realm('acme'));
        $store->add($principal);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('exists');

        $store->add(new Principal($principal->id, new Realm('globex'), PrincipalKind::Agent, PrincipalStatus::Active, $principal->createdAt, $principal->updatedAt, $principal->statusChangedAt));
    }

    #[DataProvider('implementations')]
    public function testChangesTheStatusOnlyFromTheVersionItWasReadAt(string $implementation): void
    {
        $store = $this->principalStore($implementation);
        $principal = self::principal(new Realm('acme'));
        $store->add($principal);
        $suspended = $principal->withStatus(PrincipalStatus::Suspended, new \DateTimeImmutable('2026-10-28 08:00:00.7 UTC'));

        self::assertTrue($store->changeStatus($principal, $suspended));
        self::assertEquals($suspended, $store->get(new Realm('acme'), $principal->id));
        self::assertSame(2, $suspended->statusVersion);
        self::assertEquals(new \DateTimeImmutable('2026-10-28 08:00:00 UTC'), $suspended->statusChangedAt);
        self::assertEquals($suspended->statusChangedAt, $suspended->updatedAt);
        self::assertEquals($principal->createdAt, $suspended->createdAt);

        // Read before the suspension: someone else changed it since.
        self::assertFalse($store->changeStatus($principal, $principal->withStatus(PrincipalStatus::Deleted, new \DateTimeImmutable())));
        self::assertEquals($suspended, $store->get(new Realm('acme'), $principal->id));
    }

    #[DataProvider('implementations')]
    public function testChangesNothingInAnotherRealmOrUnknown(string $implementation): void
    {
        $store = $this->principalStore($implementation);
        $principal = self::principal(new Realm('acme'));
        $store->add($principal);
        $elsewhere = new Principal($principal->id, new Realm('globex'), $principal->kind, $principal->status, $principal->createdAt, $principal->updatedAt, $principal->statusChangedAt);
        $unknown = self::principal(new Realm('acme'));

        self::assertFalse($store->changeStatus($elsewhere, $elsewhere->withStatus(PrincipalStatus::Deleted, new \DateTimeImmutable())));
        self::assertFalse($store->changeStatus($unknown, $unknown->withStatus(PrincipalStatus::Deleted, new \DateTimeImmutable())));
        self::assertEquals($principal, $store->get(new Realm('acme'), $principal->id));
        self::assertNull($store->get(new Realm('acme'), $unknown->id));
    }

    private static function principal(Realm $realm): Principal
    {
        $now = new \DateTimeImmutable('2026-10-27 18:40:00 UTC');

        return new Principal(new PrincipalId(new UuidV7()), $realm, PrincipalKind::Human, PrincipalStatus::Active, $now, $now, $now);
    }
}
