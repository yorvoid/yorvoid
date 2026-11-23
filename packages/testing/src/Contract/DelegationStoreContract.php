<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Selector\AttributeIs;
use YorvoID\Core\Authorization\Selector\IdIn;
use YorvoID\Core\Authorization\Selector\RelatedTo;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Duration;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Delegation\Delegation;
use YorvoID\Delegation\DelegationStatus;
use YorvoID\Delegation\DelegationStore;
use YorvoID\Testing\Ids;

/**
 * What every DelegationStore keeps: YorvoID's own pass these tests, and a store of your own must too.
 * Use it in a test case of yours, with the stores to check:
 *
 * ```php
 * final class MyDelegationStoreTest extends TestCase
 * {
 *     use DelegationStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function delegationStore(string $implementation): DelegationStore
 *     {
 *         return new MyDelegationStore(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * @api
 *
 * @experimental
 */
trait DelegationStoreContract
{
    /**
     * The store to check, empty: a new one for each test.
     */
    abstract protected function delegationStore(string $implementation): DelegationStore;

    /**
     * The transactions the store takes part in: the same instance within a test.
     */
    abstract protected function transactions(string $implementation): Transactions;

    #[DataProvider('implementations')]
    public function testKeepsEveryField(string $implementation): void
    {
        $store = $this->delegationStore($implementation);
        $full = self::delegation(new Realm('acme'), parent: new DelegationId(Ids::next()), purpose: 'triage', execution: 'run-7', stepUp: new StepUpRemedy(Duration::minutes(5), true, true));
        $bare = self::delegation(new Realm('acme'));
        $this->changing($implementation, static function () use ($store, $full, $bare): void {
            $store->add($full);
            $store->add($bare);
        });

        self::assertEquals($full, $store->get(new Realm('acme'), $full->id));
        self::assertEquals($bare, $store->get(new Realm('acme'), $bare->id));
        self::assertNull($store->get(new Realm('globex'), $full->id), 'Only in its realm.');
        self::assertNull($store->get(new Realm('acme'), new DelegationId(Ids::next())));
    }

    #[DataProvider('implementations')]
    public function testRefusesADuplicateIdEvenInAnotherRealm(string $implementation): void
    {
        $store = $this->delegationStore($implementation);
        $delegation = self::delegation(new Realm('acme'));
        $this->changing($implementation, static fn () => $store->add($delegation));

        $this->expectException(StorageException::class);
        $this->changing($implementation, static fn () => $store->add(self::delegation(new Realm('globex'), id: $delegation->id)), new Realm('globex'));
    }

    /**
     * Every change increments the realm's version, and only its realm's.
     */
    #[DataProvider('implementations')]
    public function testEveryChangeIncrementsTheRealmsVersion(string $implementation): void
    {
        $store = $this->delegationStore($implementation);
        $acme = new Realm('acme');
        $delegation = self::delegation($acme);

        self::assertSame(0, $store->version($acme));
        $this->changing($implementation, static fn () => $store->add($delegation));
        self::assertSame(1, $store->version($acme));
        $this->changing($implementation, static fn () => $store->revoke($delegation, $delegation->revoked(new \DateTimeImmutable('2026-11-23 19:00:00 UTC'), null, 'done')));
        self::assertSame(2, $store->version($acme));
        self::assertSame(0, $store->version(new Realm('globex')));
    }

    /**
     * A revocation is written only over an active delegation: two at once, one writes.
     */
    #[DataProvider('implementations')]
    public function testRevokesOnlyWhatsStillActive(string $implementation): void
    {
        $store = $this->delegationStore($implementation);
        $acme = new Realm('acme');
        $delegation = self::delegation($acme);
        $by = new PrincipalId(Ids::next());
        $this->changing($implementation, static fn () => $store->add($delegation));
        $revoked = $delegation->revoked(new \DateTimeImmutable('2026-11-23 19:00:00 UTC'), $by, 'no longer needed');

        self::assertTrue($this->changing($implementation, static fn (): bool => $store->revoke($delegation, $revoked)));
        self::assertFalse($this->changing($implementation, static fn (): bool => $store->revoke($delegation, $delegation->revoked(new \DateTimeImmutable('2026-11-23 19:01:00 UTC'), null, 'again'))));
        self::assertEquals($revoked, $store->get($acme, $delegation->id));
        self::assertSame(DelegationStatus::Revoked, $store->get($acme, $delegation->id)?->status);
    }

    /**
     * The active delegations issued by or to someone, in the order issued.
     */
    #[DataProvider('implementations')]
    public function testFindsTheActiveDelegationsIssuedByOrToSomeone(string $implementation): void
    {
        $store = $this->delegationStore($implementation);
        $acme = new Realm('acme');
        $ana = new PrincipalId(Ids::next());
        $first = self::delegation($acme, delegator: $ana, issuedAt: '2026-11-23 18:00:00');
        $second = self::delegation($acme, delegate: $ana, issuedAt: '2026-11-23 18:05:00');
        $revoked = self::delegation($acme, delegator: $ana, issuedAt: '2026-11-23 18:10:00');
        $other = self::delegation($acme);
        $elsewhere = self::delegation(new Realm('globex'), delegator: $ana);
        $this->changing($implementation, static function () use ($store, $first, $second, $revoked, $other): void {
            foreach ([$second, $first, $revoked, $other] as $delegation) {
                $store->add($delegation);
            }

            $store->revoke($revoked, $revoked->revoked(new \DateTimeImmutable('2026-11-23 19:00:00 UTC'), null, 'gone'));
        });
        $this->changing($implementation, static fn () => $store->add($elsewhere), new Realm('globex'));

        self::assertEquals([$first, $second], $store->involving($acme, $ana));
        self::assertSame([], $store->involving($acme, new PrincipalId(Ids::next())));
    }

    #[DataProvider('implementations')]
    public function testAChangeInAFailedTransactionIsGone(string $implementation): void
    {
        $store = $this->delegationStore($implementation);
        $acme = new Realm('acme');
        $delegation = self::delegation($acme);

        try {
            $this->changing($implementation, static function () use ($store, $delegation): never {
                $store->add($delegation);

                throw new \RuntimeException('The change fails.');
            });
        } catch (\RuntimeException) {
        }

        self::assertNull($store->get($acme, $delegation->id));
        self::assertSame(0, $store->version($acme));
    }

    /**
     * A change of authority, as YorvoID makes one: the realm's lock first.
     *
     * @template T
     *
     * @param \Closure(): T $change
     *
     * @return T
     */
    private function changing(string $implementation, \Closure $change, ?Realm $realm = null): mixed
    {
        $transactions = $this->transactions($implementation);

        return $transactions->run(static function () use ($transactions, $change, $realm): mixed {
            $transactions->lockRealm($realm ?? new Realm('acme'));

            return $change();
        });
    }

    private static function delegation(Realm $realm, ?DelegationId $id = null, ?DelegationId $parent = null, ?string $purpose = null, ?string $execution = null, ?StepUpRemedy $stepUp = null, ?PrincipalId $delegator = null, ?PrincipalId $delegate = null, string $issuedAt = '2026-11-23 18:00:00'): Delegation
    {
        $root = $delegator ?? new PrincipalId(Ids::next());

        return new Delegation(
            $id ?? new DelegationId(Ids::next()),
            $realm,
            $root,
            $delegate ?? new PrincipalId(Ids::next()),
            $parent,
            $root,
            null === $parent ? 0 : 1,
            ['acme.pages.edit', 'acme.pages.publish'],
            ['acme.pages.*'],
            new Selector('page', [new IdIn(['p-2', 'p-1']), new AttributeIs('status', 'draft'), new RelatedTo('editor', new ResourceRef('principal', 'x'))]),
            $purpose,
            new \DateTimeImmutable('2026-11-23 18:00:00 UTC'),
            new \DateTimeImmutable('2026-11-30 18:00:00 UTC'),
            ['acme.pages.edit'],
            2,
            $execution,
            $stepUp,
            (string) Ids::next(),
            41,
            new \DateTimeImmutable($issuedAt.' UTC'),
        );
    }
}
