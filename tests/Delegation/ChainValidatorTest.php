<?php

declare(strict_types=1);

namespace YorvoID\Tests\Delegation;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\DelegatedScope;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Delegation\ChainValidator;
use YorvoID\Delegation\Delegation;
use YorvoID\Testing\Ids;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Support\TestPrincipal;

/**
 * What may be delegated is decided at each use: an action that no longer exists, or may no longer be
 * delegated to a delegate of its kind, is dropped from the scope, whatever was issued.
 */
final class ChainValidatorTest extends TestCase
{
    public function testTheScopeKeepsOnlyWhatMayStillBeDelegatedToTheDelegate(): void
    {
        $world = new PolicyWorld(delegations: true);
        $ana = $world->principal('admin');
        $owner = $ana->principalId ?? throw new \LogicException();
        $bot = $world->asOperator(new CreatePrincipal($world->realm, PrincipalKind::Agent, owner: $owner))->id;
        $bea = $world->principal()->principalId ?? throw new \LogicException();
        $issued = ['acme.gone', 'acme.pages.publish', 'yorvoid.principal.suspend'];

        self::assertSame(['acme.pages.publish'], $this->scope($world, $owner, $bot, $issued)->actions, 'To an agent: neither what\'s gone nor YorvoID\'s own operation.');
        self::assertSame(['acme.pages.publish', 'yorvoid.principal.suspend'], $this->scope($world, $owner, $bea, $issued)->actions, 'To a person: what still exists.');
    }

    /**
     * @param list<string> $actions
     */
    private function scope(PolicyWorld $world, PrincipalId $from, PrincipalId $to, array $actions): DelegatedScope
    {
        $now = $world->clock->now();
        $delegation = new Delegation(new DelegationId(Ids::next()), $world->realm, $from, $to, null, $from, 0, $actions, $actions, new Selector('page'), null, $now, $now->modify('+1 day'), [], 0, null, null, 'decision', 1, $now);
        $world->memory->delegations->add($delegation);
        $request = Request::forAbility('acme.pages.publish', $world->realm, new ResourceRef('page', 'p-1'), new TestPrincipal($world->realm, $to), new Origin(OriginKind::Web), $now);
        $scope = new ChainValidator($world->memory->delegations, $world->principalStore, $world->actions())->validate($delegation->id, $to, $request);
        self::assertInstanceOf(DelegatedScope::class, $scope, $scope instanceof DelegatedScope ? '' : $scope->detail);

        return $scope;
    }
}
