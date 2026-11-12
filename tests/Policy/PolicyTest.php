<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\PrincipalAuthority;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\DeletePrincipal;
use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\Storage\InMemoryPrincipalStore;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Policy\ForgetRolesOfDeletedPrincipals;
use YorvoID\Policy\NoEscalation;
use YorvoID\Policy\Policy;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\RoleAssignment;
use YorvoID\Policy\RolePermit;
use YorvoID\Policy\Roles;
use YorvoID\Policy\StepUpForSensitiveChanges;
use YorvoID\Policy\Storage\InMemoryPolicyStore;
use YorvoID\Testing\InMemoryStorage;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Policy\Fixtures\AcmeDeclarations;
use YorvoID\Tests\Support\Permits;
use YorvoID\Tests\Support\RecordingDispatcher;

/**
 * An application's own abilities through Policy, with the actor's authority: a suspended principal
 * has no role, as in Quire's explainer.
 */
final class PolicyTest extends TestCase
{
    public function testDecidesAnAbilityWithTheActorsAuthority(): void
    {
        $realm = new Realm('acme');
        $principals = new InMemoryPrincipalStore();
        $store = new InMemoryPolicyStore();
        $now = new \DateTimeImmutable('2026-11-07 20:00:00');
        $ada = new Principal(new PrincipalId(new UuidV7()), $realm, PrincipalKind::Human, PrincipalStatus::Active, $now, $now, $now);
        $principals->add($ada);
        $store->assign(new RoleAssignment($realm, $ada->id, 'editor', $now));
        $roles = new Roles(PolicyCatalogue::of([new AcmeDeclarations()]), $store);
        $policy = new Policy(Evaluator::builtIn(new PrincipalAuthority($principals), elements: [new RolePermit($roles), new NoEscalation($roles), new StepUpForSensitiveChanges($roles)]));
        $page = new ResourceRef('page', '1');
        $origin = new Origin(OriginKind::Web);

        self::assertTrue($policy->allows(new Member($realm, $ada->id), 'acme.pages.publish', $realm, $page, $origin));
        self::assertTrue($policy->allows(new Anonymous(), 'acme.pages.view', $realm, null, $origin));
        self::assertFalse($policy->allows(new Anonymous(), 'acme.pages.publish', $realm, null, $origin));

        $principals->changeStatus($ada, new Principal($ada->id, $realm, PrincipalKind::Human, PrincipalStatus::Suspended, $now, $now, $now, 2));
        $suspended = $policy->decide(new Member($realm, $ada->id), 'acme.pages.view', $realm, $page, $origin);
        self::assertSame(Verdict::Deny, $suspended->verdict, 'Not even what everyone has: a suspended principal doesn\'t act.');
        self::assertStringStartsWith('The actor\'s authority is invalid', $suspended->reason);
    }

    public function testADeletedPrincipalKeepsNoRole(): void
    {
        $memory = new InMemoryStorage();
        $events = new RecordingDispatcher();
        $events->listen(PrincipalStatusChanging::class, new ForgetRolesOfDeletedPrincipals($memory->policy)(...));
        $principals = new Principals($memory->principals, $memory->identifiers, new SecurityLog($memory->log, $memory->transactions), $memory->transactions, events: $events);
        $realm = new Realm('acme');
        $ada = Permits::call($principals->create(...), new CreatePrincipal($realm, PrincipalKind::Human), new Origin(OriginKind::Console));
        $memory->policy->assign(new RoleAssignment($realm, $ada->id, 'editor', new \DateTimeImmutable()));

        Permits::call($principals->suspend(...), new SuspendPrincipal($realm, $ada->id), new Origin(OriginKind::Console));
        self::assertCount(1, $memory->policy->assignments($realm, $ada->id), 'Suspending keeps the roles.');
        self::assertSame(0, $memory->policy->version($realm));

        Permits::call($principals->delete(...), new DeletePrincipal($realm, $ada->id), new Origin(OriginKind::Console));
        self::assertSame([], $memory->policy->assignments($realm, $ada->id));
        self::assertSame(1, $memory->policy->version($realm), 'The policy changed.');
    }
}
