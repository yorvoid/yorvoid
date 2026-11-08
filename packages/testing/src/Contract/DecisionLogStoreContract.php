<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\DecisionLog\DecisionLogStore;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;

/**
 * What every DecisionLogStore keeps (PLT-025): YorvoID's own pass these tests, and a store of your own must too.
 * Use it in a test case of yours, with the stores to check:
 *
 * ```php
 * final class MyDecisionLogStoreTest extends TestCase
 * {
 *     use DecisionLogStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function decisionLogStore(string $implementation): DecisionLogStore
 *     {
 *         return new MyDecisionLogStore(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * @api
 */
trait DecisionLogStoreContract
{
    /**
     * The store to check, empty: a new one for each test.
     */
    abstract protected function decisionLogStore(string $implementation): DecisionLogStore;

    #[DataProvider('implementations')]
    public function testFindsEachRecordAsItWasHashed(string $implementation): void
    {
        $store = $this->decisionLogStore($implementation);
        $acme = new Realm('acme');
        $denied = self::decisionRecord($acme, new \DateTimeImmutable('2026-11-08 18:00:00.123456 UTC'), 'deny', 'ada', new ResourceRef('page', 'ünïcödé/1'));
        $ability = self::decisionRecord($acme, new \DateTimeImmutable('2026-11-08 19:00:00 +01:00'), 'permit', null, null);

        $store->add([$denied, $ability]);
        $store->add([]);

        foreach ([$denied, $ability] as $record) {
            $found = $store->find($acme, $record->id);
            self::assertNotNull($found);
            self::assertSame($record->json(), $found->json());
            self::assertSame($record->hash(), $found->hash(), 'The record found hashes as the one recorded.');
        }

        self::assertNull($store->find(new Realm('globex'), $denied->id), 'Another realm\'s decisions aren\'t found.');
        self::assertNull($store->find($acme, (string) new UuidV7()));
    }

    #[DataProvider('implementations')]
    public function testRecordsManyAtOnce(string $implementation): void
    {
        $store = $this->decisionLogStore($implementation);
        $acme = new Realm('acme');
        $records = [];

        for ($i = 0; $i < 250; ++$i) {
            $records[] = self::decisionRecord($acme, new \DateTimeImmutable('2026-11-08 18:00:00 UTC'), 'deny', null, new ResourceRef('page', (string) $i));
        }

        $store->add($records);

        self::assertSame('249', $store->find($acme, $records[249]->id)?->resource?->id);
        self::assertSame('0', $store->find($acme, $records[0]->id)?->resource?->id);
    }

    #[DataProvider('implementations')]
    public function testPrunesWhatsOlderInEveryRealm(string $implementation): void
    {
        $store = $this->decisionLogStore($implementation);
        $before = new \DateTimeImmutable('2026-11-08 18:00:00 UTC');
        $old = [self::decisionRecord(new Realm('acme'), $before->modify('-1 second'), 'deny', null, null), self::decisionRecord(new Realm('globex'), $before->modify('-1 day'), 'deny', null, null)];
        $kept = [self::decisionRecord(new Realm('acme'), $before, 'deny', null, null), self::decisionRecord(new Realm('globex'), $before->modify('+1 day'), 'deny', null, null)];
        $store->add([...$old, ...$kept]);

        self::assertSame(2, $store->prune($before));
        self::assertSame(0, $store->prune($before));

        foreach ($old as $record) {
            self::assertNull($store->find($record->realm, $record->id));
        }

        foreach ($kept as $record) {
            self::assertNotNull($store->find($record->realm, $record->id), 'One decided at the time itself is kept.');
        }
    }

    private static function decisionRecord(Realm $realm, \DateTimeImmutable $at, string $verdict, ?string $actor, ?ResourceRef $resource): DecisionRecord
    {
        return new DecisionRecord(
            (string) new UuidV7(),
            $realm,
            $at,
            null === $resource ? 'operation' : 'ability',
            null === $resource ? 'acme.badge.issue' : 'acme.pages.edit',
            null === $actor ? null : (string) new UuidV7(),
            $resource,
            $verdict,
            'permit' === $verdict ? 'ability' : null,
            'No permit applies to "acme.pages.edit".',
            [],
            'deny' === $verdict ? ['step_up'] : [],
            [['yorvoid.policy.roles', 'permit', 'fails'], ['authority', 'authority', 'holds']],
            ['policy' => 3, 'relations' => 1],
            ['web', 'request-1'],
        );
    }
}
