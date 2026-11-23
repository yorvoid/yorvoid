<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Principal\AgentProfile;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Testing\Ids;

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

    /**
     * The transactions the store takes part in: the same instance within a test.
     */
    abstract protected function transactions(string $implementation): Transactions;

    public function testPrincipalStoreRunsItsConcurrencyContract(): void
    {
        self::assertNull(Races::untested(static::class, PrincipalStoreConcurrencyContract::class));
    }

    #[DataProvider('implementations')]
    public function testPrincipalStoreTakesPartInTheTransactions(string $implementation): void
    {
        $store = $this->principalStore($implementation);
        $transactions = $this->transactions($implementation);
        $acme = new Realm('acme');
        [$failed, $seen, $kept] = [self::principal($acme), self::principal($acme), self::principal($acme)];

        InTransaction::assertGoneAfterARollback($transactions, static fn () => $store->add($failed), static fn (): bool => null === $store->get($acme, $failed->id));
        InTransaction::assertSeenInsideItsTransaction($transactions, static fn () => $store->add($seen), static fn (): bool => null !== $store->get($acme, $seen->id));
        InTransaction::assertTheTransactionGoesOn($transactions, [
            static fn () => $store->add($seen),
            static fn (): bool => $store->changeStatus($failed, $failed->withStatus(PrincipalStatus::Suspended, new \DateTimeImmutable('2026-10-28 08:00:00 UTC'))),
        ], static fn () => $store->add($kept), static fn (): bool => null !== $store->get($acme, $kept->id));
    }

    #[DataProvider('implementations')]
    public function testKeepsEveryField(string $implementation): void
    {
        $store = $this->principalStore($implementation);

        foreach (PrincipalKind::cases() as $i => $kind) {
            $status = PrincipalStatus::cases()[$i % \count(PrincipalStatus::cases())];
            $principal = new Principal(new PrincipalId(Ids::next()), new Realm('acme'), $kind, $status, new \DateTimeImmutable('2026-10-27 18:40:01 UTC'), new \DateTimeImmutable('2026-10-28 09:15:30 UTC'), new \DateTimeImmutable('2026-10-28 09:15:29 UTC'), 3 + $i);
            $store->add($principal);

            self::assertEquals($principal, $store->get(new Realm('acme'), $principal->id));
        }
    }

    /**
     * An agent's owner and profile are kept, an owner's agents found, and a deleted one no longer counts.
     */
    #[DataProvider('implementations')]
    public function testKeepsAnAgentsOwnerAndProfileAndFindsAnOwnersAgents(string $implementation): void
    {
        $store = $this->principalStore($implementation);
        $acme = new Realm('acme');
        $owner = self::principal($acme);
        $store->add($owner);
        $now = new \DateTimeImmutable('2026-11-23 18:00:00 UTC');
        $agent = new Principal(new PrincipalId(Ids::next()), $acme, PrincipalKind::Agent, PrincipalStatus::Active, $now, $now, $now, 1, $owner->id, new AgentProfile('Assistant', 'acme-agent', '1.0', 'Docs'));
        $bare = new Principal(new PrincipalId(Ids::next()), $acme, PrincipalKind::Agent, PrincipalStatus::Active, $now, $now, $now, 1, $owner->id, new AgentProfile('Ünïcode / "quoted"'));

        self::assertFalse($store->ownsAgents($acme, $owner->id));
        $store->add($agent);
        $store->add($bare);

        self::assertEquals($agent, $store->get($acme, $agent->id));
        self::assertEquals($bare, $store->get($acme, $bare->id));
        self::assertTrue($store->ownsAgents($acme, $owner->id));
        self::assertFalse($store->ownsAgents(new Realm('globex'), $owner->id), 'Only in its realm.');

        // Moved only from the owner it was read with.
        $other = self::principal($acme);
        $store->add($other);
        $moved = $agent->withOwner($other->id, $now);
        self::assertTrue($store->changeOwner($agent, $moved));
        self::assertFalse($store->changeOwner($agent, $agent->withOwner($owner->id, $now)), 'Read before the move: refused.');
        self::assertEquals($other->id, $store->get($acme, $agent->id)?->owner);
        self::assertTrue($store->ownsAgents($acme, $other->id));

        // A deleted agent doesn't count.
        $deleted = $bare->withStatus(PrincipalStatus::Deleted, $now);
        self::assertTrue($store->changeStatus($bare, $deleted));
        self::assertFalse($store->ownsAgents($acme, $owner->id));
        self::assertEquals($owner->id, $store->get($acme, $bare->id)?->owner, 'A tombstone keeps its owner.');
    }

    #[DataProvider('implementations')]
    public function testFindsNothingUnknownOrInAnotherRealm(string $implementation): void
    {
        $store = $this->principalStore($implementation);
        $principal = self::principal(new Realm('acme'));
        $store->add($principal);

        self::assertNull($store->get(new Realm('acme'), new PrincipalId(Ids::next())));
        self::assertNull($store->get(new Realm('globex'), $principal->id), 'A principal was found across realms.');
    }

    #[DataProvider('implementations')]
    public function testKnowsWhetherARealmHasAnActivePrincipal(string $implementation): void
    {
        $store = $this->principalStore($implementation);
        self::assertFalse($store->hasActive(new Realm('acme')));

        foreach ([PrincipalStatus::Invited, PrincipalStatus::Suspended, PrincipalStatus::Deleted] as $status) {
            $store->add(new Principal(new PrincipalId(Ids::next()), new Realm('acme'), PrincipalKind::Human, $status, new \DateTimeImmutable(), new \DateTimeImmutable(), new \DateTimeImmutable()));
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

    /**
     * Locking changes nothing a read sees, also when taken twice, and the status still changes from the
     * version it was read at: the lock's write is its own (D-52).
     */
    #[DataProvider('implementations')]
    public function testLockingAPrincipalChangesNothingItHolds(string $implementation): void
    {
        $store = $this->principalStore($implementation);
        $transactions = $this->transactions($implementation);
        $principal = self::principal(new Realm('acme'));
        $store->add($principal);

        $transactions->run(static function () use ($store, $principal): void {
            $store->lock($principal->realm, $principal->id);
            $store->lock($principal->realm, $principal->id);
            $store->lock($principal->realm, new PrincipalId(Ids::next()));
        });

        self::assertEquals($principal, $store->get(new Realm('acme'), $principal->id));
        self::assertTrue($store->changeStatus($principal, $principal->withStatus(PrincipalStatus::Suspended, new \DateTimeImmutable())));
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

    /**
     * An agent is moved only as it was read: in its realm, from the owner it has, to an owner.
     */
    #[DataProvider('implementations')]
    public function testMovesAnAgentOnlyAsItWasRead(string $implementation): void
    {
        $store = $this->principalStore($implementation);
        $acme = new Realm('acme');
        $owner = self::principal($acme);
        $other = self::principal($acme);
        $human = self::principal($acme);
        $now = new \DateTimeImmutable('2026-11-23 18:00:00 UTC');
        $agent = new Principal(new PrincipalId(Ids::next()), $acme, PrincipalKind::Agent, PrincipalStatus::Active, $now, $now, $now, 1, $owner->id, new AgentProfile('Assistant'));

        foreach ([$owner, $other, $human, $agent] as $principal) {
            $store->add($principal);
        }

        $elsewhere = new Principal($agent->id, new Realm('globex'), $agent->kind, $agent->status, $agent->createdAt, $agent->updatedAt, $agent->statusChangedAt, $agent->statusVersion, $agent->owner, $agent->profile);
        $unknown = new Principal(new PrincipalId(Ids::next()), $acme, PrincipalKind::Agent, PrincipalStatus::Active, $now, $now, $now, 1, $owner->id);
        $ownedHuman = new Principal($human->id, $acme, $human->kind, $human->status, $human->createdAt, $human->updatedAt, $human->statusChangedAt, $human->statusVersion, $owner->id);
        $unowned = new Principal($agent->id, $acme, $agent->kind, $agent->status, $agent->createdAt, $now, $agent->statusChangedAt, $agent->statusVersion, null, $agent->profile);

        self::assertFalse($store->changeOwner($elsewhere, $elsewhere->withOwner($other->id, $now)), 'In another realm.');
        self::assertFalse($store->changeOwner($unknown, $unknown->withOwner($other->id, $now)), 'Unknown.');
        self::assertFalse($store->changeOwner($ownedHuman, $ownedHuman->withOwner($other->id, $now)), 'Stored without an owner.');
        self::assertFalse($store->changeOwner($agent, $unowned), 'To no owner.');
        self::assertEquals($agent, $store->get($acme, $agent->id));
        self::assertNull($store->get($acme, $human->id)?->owner);
    }

    private static function principal(Realm $realm): Principal
    {
        $now = new \DateTimeImmutable('2026-10-27 18:40:00 UTC');

        return new Principal(new PrincipalId(Ids::next()), $realm, PrincipalKind::Human, PrincipalStatus::Active, $now, $now, $now);
    }
}
