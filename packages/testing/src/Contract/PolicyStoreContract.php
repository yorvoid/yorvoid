<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\AbilityChanges;
use YorvoID\Policy\GroupRoleAssignment;
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

    /**
     * The same store as another process has it: a new instance over the same data, e.g. on a second
     * connection. Override it for a store that processes share; one that lives in a single process,
     * as the in-memory one does, gives itself.
     */
    protected function samePolicyStore(string $implementation, PolicyStore $store): PolicyStore
    {
        return $store;
    }

    #[DataProvider('implementations')]
    public function testKeepsTheRolesCreatedInEachRealm(string $implementation): void
    {
        $store = $this->policyStore($implementation);
        $acme = new Realm('acme');

        self::assertSame([], $store->roles($acme));

        $store->saveRole($acme, 'reviewer', 'Reviewer');
        $store->saveRole($acme, 'helper', 'Helper');
        $store->saveRole(new Realm('other'), 'guide', 'Guide');
        $store->saveRole(new Realm('other'), 'helper', 'Other helper');
        $store->saveRole($acme, 'reviewer', 'Senior reviewer');

        self::assertSame(['helper' => 'Helper', 'reviewer' => 'Senior reviewer'], $store->roles($acme), 'By name; saving again relabels.');

        $store->saveRole($acme, 'helper', 'Acme helper');
        $store->deleteRole($acme, 'reviewer');
        self::assertSame(['helper' => 'Acme helper'], $store->roles($acme));
        self::assertSame(['guide' => 'Guide', 'helper' => 'Other helper'], $store->roles(new Realm('other')), 'Another realm\'s role of the same name stays as it was.');
        $store->deleteRole($acme, 'helper');
        self::assertSame(['guide' => 'Guide', 'helper' => 'Other helper'], $store->roles(new Realm('other')));
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

        // Out of order: they come back by role, then scope.
        $store->assign(new RoleAssignment($acme, $bob, 'editor', $at));
        $store->assign(new RoleAssignment($acme, $ada, 'manager', $at, new ResourceRef('site', '43')));
        $store->assign(new RoleAssignment($acme, $ada, 'editor', $at));
        $store->assign(new RoleAssignment($acme, $ada, 'manager', $at, $site, $until));
        $store->assign(new RoleAssignment(new Realm('other'), $ada, 'admin', $at));
        $store->assign(new RoleAssignment(new Realm('other'), $ada, 'editor', $at));

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

        self::assertEquals(self::sorted([$ada, $bob]), $store->principalsWith($acme, 'editor'), 'Sorted.');
        self::assertEquals([$ada], $store->principalsWith($acme, 'manager'), 'Each principal once.');

        self::assertTrue($store->unassign($acme, $ada, 'manager', $site));
        self::assertFalse($store->unassign($acme, $ada, 'manager', $site));
        self::assertTrue($store->unassign(new Realm('other'), $ada, 'editor', null), 'One in the whole realm.');
        self::assertCount(1, $store->assignments(new Realm('other'), $ada));
        self::assertEquals([$ada, $bob], self::sorted($store->principalsWith($acme, 'editor')), 'Only that realm\'s.');
        self::assertFalse($store->unassign($acme, $ada, 'manager', null), 'A role on a resource isn\'t one in the whole realm.');
        self::assertSame(['site:43'], array_map(static fn (RoleAssignment $assignment): string => (string) $assignment->scope, array_values(array_filter($store->assignments($acme, $ada), static fn (RoleAssignment $assignment): bool => 'manager' === $assignment->role))));

        $store->assign(new RoleAssignment(new Realm('other'), $bob, 'manager', $at));
        self::assertTrue($store->unassign($acme, $bob, 'editor', null));
        self::assertEquals([$ada], $store->principalsWith($acme, 'editor'), 'Another principal\'s role stays.');
        $store->assign(new RoleAssignment($acme, $bob, 'editor', $at));

        $store->assign(new RoleAssignment(new Realm('other'), $bob, 'editor', $at));
        $store->unassignRole($acme, 'editor');
        self::assertEquals([$bob], $store->principalsWith(new Realm('other'), 'editor'), 'Another realm keeps the role.');
        self::assertSame([], $store->principalsWith($acme, 'editor'));
        self::assertEquals([$ada], $store->principalsWith($acme, 'manager'), 'The realm\'s other roles stay.');
        self::assertEquals([$bob], $store->principalsWith(new Realm('other'), 'manager'));
        self::assertCount(1, $store->assignments(new Realm('other'), $ada), 'Another realm\'s roles stay.');

        $store->forgetPrincipal($acme, $ada);
        self::assertSame([], $store->assignments($acme, $ada));
        self::assertCount(1, $store->assignments(new Realm('other'), $ada));
    }

    #[DataProvider('implementations')]
    public function testKeepsGroupsAndTheirRoles(string $implementation): void
    {
        $store = $this->policyStore($implementation);
        $acme = new Realm('acme');
        $other = new Realm('other');
        $at = new \DateTimeImmutable('2026-11-08 02:00:00.000000 UTC');
        $until = new \DateTimeImmutable('2026-12-08 02:00:00.000000 UTC');

        self::assertSame([], $store->groups($acme));
        self::assertSame([], $store->groupAssignments($acme, []));

        $store->saveGroup($acme, 'writers', 'Writers');
        $store->saveGroup($acme, 'admins', 'Admins');
        $store->saveGroup($other, 'writers', 'Other writers');
        $store->saveGroup($acme, 'writers', 'Senior writers');
        self::assertSame(['admins' => 'Admins', 'writers' => 'Senior writers'], $store->groups($acme), 'By name; saving again relabels.');

        // Out of order: they come back by group, role, then scope.
        $store->assignGroup(new GroupRoleAssignment($acme, 'writers', 'manager', $at, new ResourceRef('site', 'b')));
        $store->assignGroup(new GroupRoleAssignment($acme, 'writers', 'editor', $at));
        $store->assignGroup(new GroupRoleAssignment($acme, 'writers', 'manager', $at, new ResourceRef('site', 'a'), $until));
        $store->assignGroup(new GroupRoleAssignment($acme, 'admins', 'admin', $at));
        $store->assignGroup(new GroupRoleAssignment($other, 'writers', 'editor', $at));
        $store->assignGroup(new GroupRoleAssignment($acme, 'writers', 'editor', $at, expiresAt: $until));

        $assignments = $store->groupAssignments($acme, ['writers', 'admins', 'ghosts']);
        self::assertSame(['admins/admin/', 'writers/editor/', 'writers/manager/site:a', 'writers/manager/site:b'], array_map(static fn (GroupRoleAssignment $assignment): string => $assignment->group.'/'.$assignment->role.'/'.$assignment->scope, $assignments));
        self::assertEquals($until, $assignments[1]->expiresAt, 'Assigning again replaces.');
        self::assertEquals($at, $assignments[1]->assignedAt);
        self::assertNull($assignments[3]->expiresAt);
        self::assertTrue($assignments[0]->realm->equals($acme));
        self::assertCount(1, $store->groupAssignments($acme, ['admins']));

        self::assertTrue($store->unassignGroup($acme, 'writers', 'manager', new ResourceRef('site', 'b')));
        self::assertFalse($store->unassignGroup($acme, 'writers', 'manager', new ResourceRef('site', 'b')));
        self::assertFalse($store->unassignGroup($acme, 'writers', 'manager', null));
        self::assertTrue($store->unassignGroup($acme, 'writers', 'editor', null));
        self::assertCount(1, $store->groupAssignments($other, ['writers']), 'Another realm\'s stays.');

        $store->assignGroup(new GroupRoleAssignment($acme, 'admins', 'editor', $at));
        $store->unassignRole($acme, 'editor');
        self::assertSame(['admin'], array_map(static fn (GroupRoleAssignment $assignment): string => $assignment->role, $store->groupAssignments($acme, ['admins'])), 'A role taken from everyone goes from groups too.');
        self::assertCount(1, $store->groupAssignments($other, ['writers']), 'In that realm only.');

        $store->deleteGroup($acme, 'writers');
        self::assertSame(['admins' => 'Admins'], $store->groups($acme));
        self::assertSame([], $store->groupAssignments($acme, ['writers']), 'Its roles go with it.');
        self::assertSame(['writers' => 'Other writers'], $store->groups($other));
        self::assertCount(1, $store->groupAssignments($other, ['writers']));
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
     * Every process reads the version from what the store holds, never from a counter of its own: what
     * one server caches against it, another must not read once the policy has changed (AUZ-031).
     */
    #[DataProvider('implementations')]
    public function testEveryProcessReadsTheSameVersion(string $implementation): void
    {
        $store = $this->policyStore($implementation);
        $acme = new Realm('acme');
        $store->bump($acme);
        $store->bump($acme);
        $other = $this->samePolicyStore($implementation, $store);

        self::assertSame(2, $other->version($acme), 'Another process reads the version the store holds.');
        self::assertSame(3, $other->bump($acme));
        self::assertSame(3, $store->version($acme), 'And this one reads its change.');
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
