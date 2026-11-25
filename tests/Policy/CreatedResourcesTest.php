<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Selector\IdIn;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Operation\Event\OperationRan;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Delegation\Change\IssueDelegation;
use YorvoID\Policy\Change\CreateGroup;
use YorvoID\Policy\Change\CreateRole;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Support\TestPrincipal;

/**
 * An operation that creates something that isn't a principal names it in its OperationRan event: a
 * role, a group, a delegation.
 */
final class CreatedResourcesTest extends TestCase
{
    public function testARoleAndAGroupAreNamed(): void
    {
        $world = new PolicyWorld();

        $world->asOperator(new CreateRole($world->realm, 'siteadmin', 'Site admin'));
        self::assertEquals(new ResourceRef('role', 'siteadmin'), self::last($world)->created);

        $world->asOperator(new CreateGroup($world->realm, 'writers', 'Writers'));
        self::assertEquals(new ResourceRef('group', 'writers'), self::last($world)->created);
    }

    public function testADelegationIsNamed(): void
    {
        $world = new PolicyWorld(delegations: true);
        $ana = $world->principal('editor');
        $bot = new TestPrincipal($world->realm, $world->asOperator(new CreatePrincipal($world->realm, PrincipalKind::Agent, owner: $ana->principalId))->id);

        $delegation = $world->as($ana, new IssueDelegation($world->realm, $bot->principalId ?? throw new \LogicException(), ['acme.pages.publish'], new Selector('page', [new IdIn(['p-1'])]), new \DateTimeImmutable('2026-11-14 20:00:00')));

        $ran = self::last($world);
        self::assertSame('yorvoid.delegation.issue', $ran->operation);
        self::assertEquals(new ResourceRef('delegation', (string) $delegation->id), $ran->created);
        self::assertSame($delegation->issuedBy, $ran->decisionId, 'The decision it was issued on.');
    }

    private static function last(PolicyWorld $world): OperationRan
    {
        $ran = $world->events->of(OperationRan::class);

        return $ran[array_key_last($ran)] ?? throw new \LogicException('Nothing ran.');
    }
}
