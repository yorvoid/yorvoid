<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\AbilityChanges;
use YorvoID\Policy\GroupRoleAssignment;
use YorvoID\Policy\RoleAssignment;
use YorvoID\Policy\Storage\InMemoryPolicyStore;
use YorvoID\Testing\Ids;

final class InMemoryPolicyStoreTest extends TestCase
{
    public function testAFailedUnitOfWorkLeavesItAsItWas(): void
    {
        $store = new InMemoryPolicyStore();
        $realm = new Realm('acme');
        $ada = new PrincipalId(Ids::next());
        $store->saveRole($realm, 'reviewer', 'Reviewer');
        $store->saveChanges($realm, new AbilityChanges(['a.x' => ['reviewer']]));
        $store->assign(new RoleAssignment($realm, $ada, 'reviewer', new \DateTimeImmutable()));
        $store->bump($realm);
        $store->saveGroup($realm, 'writers', 'Writers');
        $store->assignGroup(new GroupRoleAssignment($realm, 'writers', 'reviewer', new \DateTimeImmutable()));
        $version = $store->version($realm);
        $snapshot = $store->snapshot();

        $store->deleteRole($realm, 'reviewer');
        $store->saveChanges($realm, new AbilityChanges());
        $store->forgetPrincipal($realm, $ada);
        $store->bump($realm);
        $store->deleteGroup($realm, 'writers');
        $store->restore($snapshot);

        self::assertSame(['reviewer' => 'Reviewer'], $store->roles($realm));
        self::assertSame(['a.x' => ['reviewer']], $store->changes($realm)->grant);
        self::assertCount(1, $store->assignments($realm, $ada));
        self::assertSame($version, $store->version($realm));
        self::assertSame(['writers' => 'Writers'], $store->groups($realm));
        self::assertCount(1, $store->groupAssignments($realm, ['writers']));
    }
}
