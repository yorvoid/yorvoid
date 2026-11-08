<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\AbilityChanges;
use YorvoID\Policy\RoleAssignment;
use YorvoID\Policy\Storage\InMemoryPolicyStore;

final class InMemoryPolicyStoreTest extends TestCase
{
    public function testAFailedUnitOfWorkLeavesItAsItWas(): void
    {
        $store = new InMemoryPolicyStore();
        $realm = new Realm('acme');
        $ada = new PrincipalId(new UuidV7());
        $store->saveRole($realm, 'reviewer', 'Reviewer');
        $store->saveChanges($realm, new AbilityChanges(['a.x' => ['reviewer']]));
        $store->assign(new RoleAssignment($realm, $ada, 'reviewer', new \DateTimeImmutable()));
        $store->bump($realm);
        $snapshot = $store->snapshot();

        $store->deleteRole($realm, 'reviewer');
        $store->saveChanges($realm, new AbilityChanges());
        $store->forgetPrincipal($realm, $ada);
        $store->bump($realm);
        $store->restore($snapshot);

        self::assertSame(['reviewer' => 'Reviewer'], $store->roles($realm));
        self::assertSame(['a.x' => ['reviewer']], $store->changes($realm)->grant);
        self::assertCount(1, $store->assignments($realm, $ada));
        self::assertSame(1, $store->version($realm));
    }
}
