<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Realm;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\FlowStore;

/**
 * What every FlowStore keeps: YorvoID's own pass these tests, and a store of your own must too. Use it in
 * a test case of yours, with the stores to check:
 *
 * ```php
 * final class MyFlowStoreTest extends TestCase
 * {
 *     use FlowStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function flowStore(string $implementation): FlowStore
 *     {
 *         return $this->flows ??= new MyFlowStore(...); // empty, new for each test, the same within it
 *     }
 * }
 * ```
 *
 * A store may keep only a few flows for one browser, as the Symfony bridge's does: these tests keep two.
 *
 * @api
 */
trait FlowStoreContract
{
    /**
     * The store to check, empty: a new one for each test, the same within it.
     */
    abstract protected function flowStore(string $implementation): FlowStore;

    #[DataProvider('implementations')]
    public function testKeepsEveryField(string $implementation): void
    {
        $store = $this->flowStore($implementation);
        $state = new FlowState('flow-1', 'yorvoid.sign_in', new Realm('acme'), new \DateTimeImmutable('2026-10-27 18:40:00 UTC'), ['passkey', 'email'], 'recovery', ['attempts' => 2, 'email' => 'jane@example.com', 'remember' => true, 'steps' => ['a' => null]]);

        $store->save($state);

        self::assertEquals($state, $store->get('flow-1'));
        self::assertNull($store->get('flow-2'));
    }

    #[DataProvider('implementations')]
    public function testSavingAFlowAgainReplacesIt(string $implementation): void
    {
        $store = $this->flowStore($implementation);
        $store->save(self::state('flow-1'));
        $store->save(self::state('flow-2'));

        $moved = self::state('flow-1', ['passkey'], 'email', ['sent' => true]);
        $store->save($moved);

        self::assertEquals($moved, $store->get('flow-1'));
        self::assertEquals(self::state('flow-2'), $store->get('flow-2'), 'Saving one flow changed another.');
    }

    #[DataProvider('implementations')]
    public function testDeletesOnlyTheFlowNamed(string $implementation): void
    {
        $store = $this->flowStore($implementation);
        $store->save(self::state('flow-1'));
        $store->save(self::state('flow-2'));

        $store->delete('flow-1');
        $store->delete('unknown');

        self::assertNull($store->get('flow-1'));
        self::assertEquals(self::state('flow-2'), $store->get('flow-2'));
    }

    /**
     * @param list<string>         $passed
     * @param array<string, mixed> $data
     */
    private static function state(string $id, array $passed = [], ?string $current = null, array $data = []): FlowState
    {
        return new FlowState($id, 'yorvoid.sign_in', Realm::default(), new \DateTimeImmutable('2026-10-27 19:40:00 UTC'), $passed, $current, $data);
    }
}
