<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Support\TestPrincipal;

/**
 * An agent never holds its owner's authority (AGT-003): decided as any principal, by what it was given
 * itself, none by default. What it does for its owner comes with delegations.
 */
final class AgentsHoldNothingOfTheirOwnersTest extends TestCase
{
    public function testAnAgentHoldsOnlyWhatItWasGiven(): void
    {
        $world = new PolicyWorld();
        $owner = $world->principal('manager', 'editor');
        \assert(null !== $owner->principalId);
        $agent = $world->asOperator(new CreatePrincipal($world->realm, PrincipalKind::Agent, owner: $owner->principalId));
        $actor = new TestPrincipal($world->realm, $agent->id);
        $page = new ResourceRef('page', '1');

        self::assertTrue($world->allows($owner, 'acme.sites.update', $page));
        self::assertFalse($world->allows($actor, 'acme.sites.update', $page), 'Its owner\'s roles aren\'t its own.');
        self::assertFalse($world->allows($actor, 'acme.pages.publish', $page));

        $world->asOperator(new AssignRole($world->realm, $agent->id, 'editor'));
        self::assertTrue($world->allows($actor, 'acme.pages.publish', $page), 'A role given to it, it holds.');
        self::assertFalse($world->allows($actor, 'acme.sites.update', $page), 'Only that one.');
    }
}
