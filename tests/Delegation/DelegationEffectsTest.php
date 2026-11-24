<?php

declare(strict_types=1);

namespace YorvoID\Tests\Delegation;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\EffectPart;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Selector\IdIn;
use YorvoID\Core\Authorization\Selector\RelatedTo;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Delegation\Change\IssueDelegation;
use YorvoID\Delegation\Change\RevokeDelegation;
use YorvoID\Delegation\Change\RevokeDelegationsOf;
use YorvoID\Delegation\DelegationEffects;
use YorvoID\Testing\Ids;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Support\TestPrincipal;

/**
 * What issuing gives and revoking takes, as no-escalation weighs it: the abilities that hold the
 * actions named, once each and sorted, an operation's own name where it needs none; what each
 * delegation revoked gave, and nothing for one that isn't there.
 */
final class DelegationEffectsTest extends TestCase
{
    public function testIssuingGivesTheAbilitiesThatHoldWhatItNames(): void
    {
        $world = new PolicyWorld(delegations: true);
        $bea = $world->principal();

        $gives = $this->effects($world)->effectOf($this->request($world, $bea, new IssueDelegation($world->realm, $bea->principalId ?? throw new \LogicException(), ['yorvoid.principal.*', 'yorvoid.identifier.*', 'acme.nothing.*'], new Selector('page'), new \DateTimeImmutable('2026-11-14 20:00:00'))))->gives;

        self::assertEquals([new EffectPart(abilities: ['acme.nothing.*', 'yorvoid.identifier.verify', 'yorvoid.principals.manage'])], $gives, 'Not "yorvoid.principals.recover": a longer name that starts alike.');
    }

    /**
     * A delegation of the pages someone has a relation to says so, for no-escalation to verify; one
     * that names its pages, or is passed on, gives what it names on each, and says nothing of relations.
     */
    public function testOnlyARootDelegationWithoutIdsIsThroughARelation(): void
    {
        $world = new PolicyWorld(delegations: true);
        $bea = $world->principal();
        $id = $bea->principalId ?? throw new \LogicException();
        $hers = new RelatedTo('editor', new ResourceRef('principal', (string) $id));
        $gives = fn (IssueDelegation $command): array => $this->effects($world)->effectOf($this->request($world, $bea, $command))->gives;
        $until = new \DateTimeImmutable('2026-11-14 20:00:00');

        self::assertEquals([new EffectPart(abilities: ['acme.pages.publish'], viaRelations: ['editor'], onType: 'page', subject: new ResourceRef('principal', (string) $id))], $gives(new IssueDelegation($world->realm, $id, ['acme.pages.publish'], new Selector('page', [$hers]), $until)));
        self::assertEquals([new EffectPart(abilities: ['acme.pages.publish'], on: new ResourceRef('page', 'p-1')), new EffectPart(abilities: ['acme.pages.publish'], on: new ResourceRef('page', 'p-2'))], $gives(new IssueDelegation($world->realm, $id, ['acme.pages.publish'], new Selector('page', [$hers, new IdIn(['p-1', 'p-2'])]), $until)), 'Named by ID.');
        self::assertEquals([new EffectPart(abilities: ['acme.pages.publish'])], $gives(new IssueDelegation($world->realm, $id, ['acme.pages.publish'], new Selector('page', [$hers]), $until, parent: new DelegationId(Ids::next()))), 'Passed on.');
    }

    public function testRevokingTakesWhatEachActiveDelegationGave(): void
    {
        $world = new PolicyWorld(delegations: true);
        $ana = $world->principal('editor');
        $bea = $world->principal();
        $id = $bea->principalId ?? throw new \LogicException();
        $world->as($ana, new IssueDelegation($world->realm, $id, ['acme.pages.publish'], new Selector('page'), new \DateTimeImmutable('2026-11-14 20:00:00')));
        $world->as($ana, new IssueDelegation($world->realm, $id, ['acme.pages.view_drafts'], new Selector('page'), new \DateTimeImmutable('2026-11-14 20:00:00')));
        $effects = $this->effects($world);

        self::assertEquals([new EffectPart(abilities: ['acme.pages.publish']), new EffectPart(abilities: ['acme.pages.view_drafts'])], $effects->effectOf($this->request($world, $ana, new RevokeDelegationsOf($world->realm, $id, 'left')))->takes);
        self::assertSame([], $effects->effectOf($this->request($world, $ana, new RevokeDelegation($world->realm, new DelegationId(Ids::next()), 'gone')))->takes, 'None there.');
        self::assertSame([], $effects->effectOf(Request::forAbility('acme.pages.view', $world->realm, null, $ana, new Origin(OriginKind::Web), new \DateTimeImmutable()))->takes, 'Not one of its commands.');
    }

    private function effects(PolicyWorld $world): DelegationEffects
    {
        return new DelegationEffects($world->actions(), $world->delegationStore);
    }

    /**
     * @param Command<mixed> $command
     */
    private function request(PolicyWorld $world, TestPrincipal $by, Command $command): Request
    {
        $name = match ($command::class) {
            IssueDelegation::class => 'yorvoid.delegation.issue',
            RevokeDelegation::class => 'yorvoid.delegation.revoke',
            default => 'yorvoid.delegation.revoke_all',
        };

        return Request::forOperation($world->catalogue->get($name), $command, $by, new Origin(OriginKind::Web), new \DateTimeImmutable());
    }
}
