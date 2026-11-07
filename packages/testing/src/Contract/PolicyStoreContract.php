<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\AbilityChanges;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\RoleAssignment;

/**
 * What every PolicyStore keeps (PLT-025): YorvoID's own pass these tests, and a store of your own must too.
 * Use it in a test case of yours, with the stores to check:
 *
 * ```php
 * final class MyPolicyStoreTest extends TestCase
 * {
 *     use PolicyStoreContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function policyStore(string $implementation): PolicyStore
 *     {
 *         return new MyPolicyStore(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * @api
 */
trait PolicyStoreContract
{
    /**
     * The store to check, empty: a new one for each test.
     */
    abstract protected function policyStore(string $implementation): PolicyStore;

    #[DataProvider('implementations')]
    public function testKeepsTheRolesCreatedInEachRealm(string $implementation): void
    {
        $store = $this->policyStore($implementation);
        $acme = new Realm('acme');

        self::assertSame([], $store->roles($acme));

        $store->saveRole($acme, 'reviewer', 'Reviewer');
        $store->saveRole($acme, 'helper', 'Helper');
        $store->saveRole(new Realm('other'), 'guide', 'Guide');
        $store->saveRole($acme, 'reviewer', 'Senior reviewer');

        self::assertSame(['helper' => 'Helper', 'reviewer' => 'Senior reviewer'], $store->roles($acme), 'By name; saving again relabels.');

        $store->deleteRole($acme, 'helper');
        self::assertSame(['reviewer' => 'Senior reviewer'], $store->roles($acme));
        self::assertSame(['guide' => 'Guide'], $store->roles(new Realm('other')));
    }

    #[DataProvider('implementations')]
    public function testKeepsEachRealmsChangesToTheDefaults(string $implementation): void
    {
        $store = $this->policyStore($implementation);
        $acme = new Realm('acme');
        $changes = new AbilityChanges(['acme.pages.view_drafts' => ['member', 'helper']], ['acme.pages.delete' => ['editor']]);

        self::assertTrue($store->changes($acme)->isEmpty());

        $store->saveChanges($acme, $changes);
        $store->saveChanges(new Realm('other'), new AbilityChanges(['acme.pages.delete' => ['member']]));

        self::assertTrue($changes->equals($store->changes($acme)));
        self::assertSame(['acme.pages.view_drafts' => ['helper', 'member']], $store->changes($acme)->grant);

        $store->saveChanges($acme, new AbilityChanges([], ['acme.pages.view' => ['everyone']]));
        self::assertSame([], $store->changes($acme)->grant, 'Saving replaces the realm\'s changes.');
        self::assertSame(['acme.pages.view' => ['everyone']], $store->changes($acme)->revoke);
        self::assertSame(['acme.pages.delete' => ['member']], $store->changes(new Realm('other'))->grant);
    }

    #[DataProvider('implementations')]
    public function testKeepsWhoHasWhichRoleAndWhere(string $implementation): void
    {
        $store = $this->policyStore($implementation);
        $acme = new Realm('acme');
        $ada = new PrincipalId(new UuidV7());
        $bob = new PrincipalId(new UuidV7());
        $at = new \DateTimeImmutable('2026-11-07 20:00:00.000000 UTC');
        $until = new \DateTimeImmutable('2026-12-07 20:00:00.000000 UTC');
        $site = new ResourceRef('site', '42');

        self::assertSame([], $store->assignments($acme, $ada));

        $store->assign(new RoleAssignment($acme, $ada, 'editor', $at));
        $store->assign(new RoleAssignment($acme, $ada, 'manager', $at, $site, $until));
        $store->assign(new RoleAssignment($acme, $ada, 'manager', $at, new ResourceRef('site', '43')));
        $store->assign(new RoleAssignment($acme, $bob, 'editor', $at));
        $store->assign(new RoleAssignment(new Realm('other'), $ada, 'admin', $at));

        $assignments = $store->assignments($acme, $ada);
        self::assertSame(['editor', 'manager', 'manager'], array_map(static fn (RoleAssignment $assignment): string => $assignment->role, $assignments));
        self::assertSame(['', 'site:42', 'site:43'], array_map(static fn (RoleAssignment $assignment): string => (string) $assignment->scope, $assignments), 'By role, then scope.');
        self::assertEquals($at, $assignments[1]->assignedAt);
        self::assertEquals($until, $assignments[1]->expiresAt);
        self::assertNull($assignments[0]->expiresAt);
        self::assertTrue($assignments[0]->realm->equals($acme) && $assignments[0]->principalId->equals($ada));

        $store->assign(new RoleAssignment($acme, $ada, 'manager', $at, $site));
        self::assertNull($store->assignments($acme, $ada)[1]->expiresAt, 'Assigning again replaces the one with its principal, role and scope.');
        self::assertCount(3, $store->assignments($acme, $ada));

        self::assertEquals([$ada, $bob], self::sorted($store->principalsWith($acme, 'editor')));
        self::assertEquals([$ada], $store->principalsWith($acme, 'manager'), 'Each principal once.');

        self::assertTrue($store->unassign($acme, $ada, 'manager', $site));
        self::assertFalse($store->unassign($acme, $ada, 'manager', $site));
        self::assertFalse($store->unassign($acme, $ada, 'manager', null), 'A role on a resource isn\'t one in the whole realm.');
        self::assertSame(['site:43'], array_map(static fn (RoleAssignment $assignment): string => (string) $assignment->scope, array_values(array_filter($store->assignments($acme, $ada), static fn (RoleAssignment $assignment): bool => 'manager' === $assignment->role))));

        $store->unassignRole($acme, 'editor');
        self::assertSame([], $store->principalsWith($acme, 'editor'));
        self::assertCount(1, $store->assignments(new Realm('other'), $ada), 'Another realm\'s roles stay.');

        $store->forgetPrincipal($acme, $ada);
        self::assertSame([], $store->assignments($acme, $ada));
        self::assertCount(1, $store->assignments(new Realm('other'), $ada));
    }

    #[DataProvider('implementations')]
    public function testCountsEachRealmsVersion(string $implementation): void
    {
        $store = $this->policyStore($implementation);
        $acme = new Realm('acme');

        self::assertSame(0, $store->version($acme));
        self::assertSame(1, $store->bump($acme));
        self::assertSame(2, $store->bump($acme));
        self::assertSame(2, $store->version($acme));
        self::assertSame(0, $store->version(new Realm('other')));
        self::assertSame(1, $store->bump(new Realm('other')));
    }

    /**
     * @param list<PrincipalId> $ids
     *
     * @return list<PrincipalId>
     */
    private static function sorted(array $ids): array
    {
        usort($ids, static fn (PrincipalId $a, PrincipalId $b): int => $a->uuid->toRfc4122() <=> $b->uuid->toRfc4122());

        return $ids;
    }
}
