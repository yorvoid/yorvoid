<?php

declare(strict_types=1);

namespace YorvoID\Tests\Delegation;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Selector\AttributeIs;
use YorvoID\Core\Authorization\Selector\IdIn;
use YorvoID\Core\Authorization\Selector\RelatedTo;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\UnderDelegation;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Operation\ViewingAs;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Delegation\Change\IssueDelegation;
use YorvoID\Delegation\Change\RevokeDelegation;
use YorvoID\Delegation\Delegation;
use YorvoID\Delegation\DelegationRefused;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\UnassignRole;
use YorvoID\Relations\Change\AddRelation;
use YorvoID\Relations\Subject;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\TestPrincipal;

/**
 * Decisions by an actor under a delegation (06 §5.3): its chain now, the request within the leaf's
 * scope, the root permitted the same now, no forbid; never more than the root may, and its own roles
 * don't count.
 */
final class DelegatedDecisionsTest extends DatabaseTestCase
{
    private const string EXPIRES = '2026-11-14 20:00:00';

    #[DataProvider('implementations')]
    public function testAnAgentDoesWhatItWasDelegatedOnWhatItWasDelegatedOnly(string $implementation): void
    {
        $world = $this->world($implementation);
        [$ana, $bot] = $this->people($world);
        $delegation = $this->delegate($world, $ana, $bot, ['acme.pages.publish'], new Selector('page', [new IdIn(['p-1'])]));
        $under = new UnderDelegation($bot, $delegation->id);

        $permitted = $this->decide($world, $under, 'acme.pages.publish', 'p-1');
        self::assertSame(Verdict::Permit, $permitted->verdict, $permitted->reason);
        self::assertSame(['links' => [(string) $delegation->id], 'root' => (string) self::id($ana)], $permitted->read->delegation, 'The record names the chain.');
        self::assertSame(Verdict::Deny, $this->decide($world, $under, 'acme.pages.publish', 'p-2')->verdict, 'Another page.');
        self::assertSame(Verdict::Deny, $this->decide($world, $under, 'acme.pages.view_drafts', 'p-1')->verdict, 'Another action, though the root holds it.');
        self::assertSame(Verdict::Deny, $this->decide($world, $bot, 'acme.pages.publish', 'p-1')->verdict, 'Not under the delegation: it holds nothing.');
    }

    /**
     * The root's authority now bounds it: a role taken from the root stops the next decision.
     */
    #[DataProvider('implementations')]
    public function testTheRootLosingItsRoleStopsTheNextDecision(string $implementation): void
    {
        $world = $this->world($implementation);
        [$ana, $bot] = $this->people($world);
        $under = new UnderDelegation($bot, $this->delegate($world, $ana, $bot, ['acme.pages.publish'], new Selector('page'))->id);
        self::assertSame(Verdict::Permit, $this->decide($world, $under, 'acme.pages.publish', 'p-1')->verdict);

        $world->asOperator(new UnassignRole($world->realm, self::id($ana), 'editor'));

        $refused = $this->decide($world, $under, 'acme.pages.publish', 'p-1');
        self::assertSame(Verdict::Deny, $refused->verdict);
        self::assertStringContainsString('Its root may not now', $refused->reason);
    }

    #[DataProvider('implementations')]
    public function testARevokedExpiredOrSomeoneElsesDelegationDecidesNothing(string $implementation): void
    {
        $world = $this->world($implementation);
        [$ana, $bot] = $this->people($world);
        $other = $this->agent($world, $ana);
        $delegation = $this->delegate($world, $ana, $bot, ['acme.pages.publish'], new Selector('page'));

        self::assertSame(Verdict::Deny, $this->decide($world, new UnderDelegation($other, $delegation->id), 'acme.pages.publish', 'p-1')->verdict, 'Named by another agent.');

        $world->clock->modify(self::EXPIRES);
        self::assertSame(Verdict::Deny, $this->decide($world, new UnderDelegation($bot, $delegation->id), 'acme.pages.publish', 'p-1')->verdict, 'At its expiry.');
        $world->clock->modify('2026-11-08 20:00:00');

        $world->as($ana, new RevokeDelegation($world->realm, $delegation->id, 'done'));
        self::assertSame(Verdict::Deny, $this->decide($world, new UnderDelegation($bot, $delegation->id), 'acme.pages.publish', 'p-1')->verdict, 'Revoked.');
    }

    #[DataProvider('implementations')]
    public function testASuspendedAgentOrRootDecidesNothing(string $implementation): void
    {
        $world = $this->world($implementation);
        [$ana, $bot] = $this->people($world);
        $under = new UnderDelegation($bot, $this->delegate($world, $ana, $bot, ['acme.pages.publish'], new Selector('page'))->id);

        $world->asOperator(new SuspendPrincipal($world->realm, self::id($ana)));
        self::assertSame(Verdict::Deny, $this->decide($world, $under, 'acme.pages.publish', 'p-1')->verdict, 'A suspended root stops every chain through it.');
    }

    /**
     * D5 and the purpose: a delegation bound to a task or a purpose doesn't match a request without one.
     */
    #[DataProvider('implementations')]
    public function testATaskOrPurposeBoundDelegationNeedsTheRequestsToMatch(string $implementation): void
    {
        $world = $this->world($implementation);
        [$ana, $bot] = $this->people($world);
        $delegation = $world->as($ana, new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish'], new Selector('page'), new \DateTimeImmutable(self::EXPIRES), purpose: 'release-notes', execution: 'run-1'));

        self::assertSame(Verdict::Permit, $this->decide($world, new UnderDelegation($bot, $delegation->id, 'release-notes', 'run-1'), 'acme.pages.publish', 'p-1')->verdict);
        self::assertSame(Verdict::Deny, $this->decide($world, new UnderDelegation($bot, $delegation->id, 'release-notes'), 'acme.pages.publish', 'p-1')->verdict, 'No task.');
        self::assertSame(Verdict::Deny, $this->decide($world, new UnderDelegation($bot, $delegation->id, 'release-notes', 'run-2'), 'acme.pages.publish', 'p-1')->verdict, 'Another task.');
        self::assertSame(Verdict::Deny, $this->decide($world, new UnderDelegation($bot, $delegation->id, null, 'run-1'), 'acme.pages.publish', 'p-1')->verdict, 'No purpose.');
    }

    /**
     * The agent's own roles don't count under a delegation: only the root's, within the leaf's scope.
     */
    #[DataProvider('implementations')]
    public function testTheAgentsOwnRolesDontCountUnderADelegation(string $implementation): void
    {
        $world = $this->world($implementation);
        $ana = $world->principal('member');
        $bea = $world->principal('editor');
        $bot = $this->agent($world, $bea);
        $world->asOperator(new AssignRole($world->realm, self::id($bot), 'manager'));
        $under = new UnderDelegation($bot, $this->delegate($world, $bea, $bot, ['acme.pages.publish'], new Selector('page'))->id);

        self::assertSame(Verdict::Deny, $this->decide($world, $under, 'acme.sites.update', 's-1')->verdict, 'Its own manager role, not under the delegation.');
        self::assertSame(Verdict::Permit, $this->decide($world, $bot, 'acme.sites.update', 's-1')->verdict, 'As itself, it has it.');
        self::assertNotSame(Verdict::Permit, $this->decide($world, $ana, 'acme.pages.publish', 'p-1')->verdict);
    }

    /**
     * Conditions only policy and relations can match: an attribute, a relation.
     */
    #[DataProvider('implementations')]
    public function testConditionsOnRelationsAreMatchedNow(string $implementation): void
    {
        $world = $this->world($implementation);
        [$ana, $bot] = $this->people($world);
        $mine = new RelatedTo('editor', new ResourceRef('principal', (string) self::id($ana)));
        $under = new UnderDelegation($bot, $this->delegate($world, $ana, $bot, ['acme.pages.publish'], new Selector('page', [$mine]))->id);
        $world->asOperator(new AddRelation($world->realm, new ResourceRef('page', 'p-1'), 'editor', Subject::principal(self::id($ana))));

        self::assertSame(Verdict::Permit, $this->decide($world, $under, 'acme.pages.publish', 'p-1')->verdict, 'Her page.');
        self::assertSame(Verdict::Deny, $this->decide($world, $under, 'acme.pages.publish', 'p-2')->verdict, 'Not hers.');
        $unknown = $this->decide($world, new UnderDelegation($bot, $this->delegate($world, $ana, $bot, ['acme.pages.publish'], new Selector('page', [new AttributeIs('acme.unknown', 'x')]))->id), 'acme.pages.publish', 'p-1');
        self::assertSame(Verdict::Indeterminate, $unknown->verdict, 'An attribute nothing gives: unknown, never a match.');
    }

    /**
     * A delegation passed on: the leaf's scope, the whole chain now, the root at the top.
     */
    #[DataProvider('implementations')]
    public function testAChainPassedOnStopsWhenItsParentIsRevoked(string $implementation): void
    {
        $world = $this->world($implementation);
        $ana = $world->principal('editor');
        $bea = $world->principal();
        $bot = $this->agent($world, $bea);
        $parent = $world->as($ana, new IssueDelegation($world->realm, self::id($bea), ['acme.pages.publish'], new Selector('page'), new \DateTimeImmutable(self::EXPIRES), redelegable: ['acme.pages.publish'], depth: 1));
        $child = $world->as($bea, new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish'], new Selector('page', [new IdIn(['p-1'])]), new \DateTimeImmutable('2026-11-10 20:00:00'), parent: $parent->id));
        $under = new UnderDelegation($bot, $child->id);

        $permitted = $this->decide($world, $under, 'acme.pages.publish', 'p-1');
        self::assertSame(Verdict::Permit, $permitted->verdict);
        self::assertSame(['links' => [(string) $child->id, (string) $parent->id], 'root' => (string) self::id($ana)], $permitted->read->delegation);

        $world->as($ana, new RevokeDelegation($world->realm, $parent->id, 'done'));
        self::assertSame(Verdict::Deny, $this->decide($world, $under, 'acme.pages.publish', 'p-1')->verdict);
    }

    /**
     * Passing a delegation on checks its root now: a root that lost the authority can't be passed on from.
     */
    #[DataProvider('implementations')]
    public function testPassingOnFromARootThatLostItIsRefused(string $implementation): void
    {
        $world = $this->world($implementation);
        $ana = $world->principal('editor');
        $bea = $world->principal();
        $bot = $this->agent($world, $bea);
        $parent = $world->as($ana, new IssueDelegation($world->realm, self::id($bea), ['acme.pages.publish'], new Selector('page'), new \DateTimeImmutable(self::EXPIRES), redelegable: ['acme.pages.publish'], depth: 1));
        $world->asOperator(new UnassignRole($world->realm, self::id($ana), 'editor'));

        try {
            $world->as($bea, new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish'], new Selector('page'), new \DateTimeImmutable('2026-11-10 20:00:00'), parent: $parent->id));
            self::fail('Passed on.');
        } catch (DelegationRefused $refused) {
            self::assertSame('root_not_permitted', $refused->reason);
        }
    }

    /**
     * A delegation of what a relation gives, on the resources the delegator has that relation to: held by
     * the relation's definition, decided on each resource at use.
     */
    #[DataProvider('implementations')]
    public function testWhatARelationGivesIsDelegatedOnWhatTheDelegatorHasItTo(string $implementation): void
    {
        $world = $this->world($implementation);
        $ana = $world->principal();
        $bot = $this->agent($world, $ana);
        $world->asOperator(new AddRelation($world->realm, new ResourceRef('page', 'p-1'), 'editor', Subject::principal(self::id($ana))));
        $hers = new Selector('page', [new RelatedTo('editor', new ResourceRef('principal', (string) self::id($ana)))]);
        $under = new UnderDelegation($bot, $this->delegate($world, $ana, $bot, ['acme.pages.publish'], $hers)->id);

        self::assertSame(Verdict::Permit, $this->decide($world, $under, 'acme.pages.publish', 'p-1')->verdict, 'Her page.');
        self::assertSame(Verdict::Deny, $this->decide($world, $under, 'acme.pages.publish', 'p-2')->verdict, 'Not hers.');

        try {
            $this->delegate($world, $ana, $bot, ['acme.pages.delete'], $hers);
            self::fail('What no relation of hers gives.');
        } catch (NotPermitted) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * A grant by relation is the delegator's own, through one principal, them: a second principal, or
     * someone else's relation, is weighed as the abilities themselves; more conditions only narrow it, and
     * a condition on another kind of subject doesn't take its place.
     */
    public function testARelationGrantIsThroughTheDelegatorAlone(): void
    {
        $world = $this->world('memory');
        $ana = $world->principal();
        $bea = $world->principal();
        $bot = $this->agent($world, $ana);
        $editor = static fn (TestPrincipal $principal): RelatedTo => new RelatedTo('editor', new ResourceRef('principal', (string) self::id($principal)));
        $world->asOperator(new AddRelation($world->realm, new ResourceRef('page', 'p-1'), 'editor', Subject::principal(self::id($ana))));
        $world->asOperator(new AddRelation($world->realm, new ResourceRef('page', 'p-2'), 'editor', Subject::principal(self::id($bea))));

        foreach ([
            'and a group\'s' => [$editor($ana), new RelatedTo('editor', new ResourceRef('group', 'g-1'))],
            'two relations of hers' => [$editor($ana), new RelatedTo('viewer', new ResourceRef('principal', (string) self::id($ana)))],
        ] as $case => $conditions) {
            try {
                $this->delegate($world, $ana, $bot, ['acme.pages.publish'], new Selector('page', $conditions));
            } catch (NotPermitted $refused) {
                self::fail($case.': '.$refused->decision->reason);
            }
        }

        foreach (['someone else\'s' => [$editor($bea)], 'two principals' => [$editor($ana), $editor($bea)]] as $case => $conditions) {
            try {
                $this->delegate($world, $ana, $bot, ['acme.pages.publish'], new Selector('page', $conditions));
                self::fail('Delegated: '.$case);
            } catch (NotPermitted) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Whether the relation a delegation names holds couldn't be told within the bound: unknown, never a
     * match.
     */
    public function testARelationThatCantBeToldIsUnknown(): void
    {
        $world = new PolicyWorld(maxDepth: 1, delegations: true);
        [$ana, $bot] = $this->people($world);
        $world->asOperator(new AddRelation($world->realm, new ResourceRef('page', 'p-1'), 'parent', new Subject(new ResourceRef('site', 's-1'))));
        $world->asOperator(new AddRelation($world->realm, new ResourceRef('site', 's-1'), 'viewer', Subject::principal(self::id($ana))));
        $under = new UnderDelegation($bot, $this->delegate($world, $ana, $bot, ['acme.pages.publish'], new Selector('page', [new RelatedTo('viewer', new ResourceRef('principal', (string) self::id($ana)))]))->id);

        $decision = $this->decide($world, $under, 'acme.pages.publish', 'p-1');
        self::assertSame(Verdict::Indeterminate, $decision->verdict, $decision->reason);
        self::assertStringContainsString('Whether the relation holds couldn\'t be established.', $decision->reason);
    }

    /**
     * Passing on an operation checks that the root holds its ability now, not an ability of its name.
     */
    public function testPassingOnAnOperationChecksTheRootHoldsItsAbility(): void
    {
        $world = $this->world('memory');
        $ana = $world->principal('admin');
        $bea = $world->principal();
        $cara = $world->principal();
        $parent = $world->as($ana, new IssueDelegation($world->realm, self::id($bea), ['yorvoid.principal.suspend'], new Selector('principal'), new \DateTimeImmutable(self::EXPIRES), redelegable: ['yorvoid.principal.suspend'], depth: 1));

        $child = $world->as($bea, new IssueDelegation($world->realm, self::id($cara), ['yorvoid.principal.suspend'], new Selector('principal'), new \DateTimeImmutable('2026-11-10 20:00:00'), parent: $parent->id));
        self::assertEquals([$parent->id, self::id($ana)], [$child->parent, $child->root]);
    }

    public function testADelegationIsUsedByTheActorThatAuthenticatedNeverNested(): void
    {
        $world = $this->world('memory');
        [$ana, $bot] = $this->people($world);
        $id = $this->delegate($world, $ana, $bot, ['acme.pages.publish'], new Selector('page'))->id;

        foreach ([static fn () => new UnderDelegation(new UnderDelegation($bot, $id), $id), static fn () => new UnderDelegation(new ViewingAs($ana, $bot), $id)] as $nested) {
            try {
                $nested();
                self::fail('Nested.');
            } catch (IntegratorError $e) {
                self::assertSame('concepts/delegation.md#using-a-delegation', $e->docs);
            }
        }
    }

    private function world(string $implementation): PolicyWorld
    {
        return new PolicyWorld(stack: 'memory' === $implementation ? null : $this->stack($implementation), delegations: true);
    }

    /**
     * @return array{TestPrincipal, TestPrincipal}
     */
    private function people(PolicyWorld $world): array
    {
        $ana = $world->principal('editor');

        return [$ana, $this->agent($world, $ana)];
    }

    private function agent(PolicyWorld $world, TestPrincipal $owner): TestPrincipal
    {
        return new TestPrincipal($world->realm, $world->asOperator(new CreatePrincipal($world->realm, PrincipalKind::Agent, owner: self::id($owner)))->id);
    }

    /**
     * @param list<string> $actions
     */
    private function delegate(PolicyWorld $world, TestPrincipal $from, TestPrincipal $to, array $actions, Selector $resources): Delegation
    {
        $delegation = $world->as($from, new IssueDelegation($world->realm, self::id($to), $actions, $resources, new \DateTimeImmutable(self::EXPIRES)));

        return $delegation;
    }

    private function decide(PolicyWorld $world, \YorvoID\Core\Operation\Actor $actor, string $ability, string $id): Decision
    {
        return $world->policy->decide($actor, $ability, $world->realm, new ResourceRef(str_starts_with($id, 's-') ? 'site' : 'page', $id), new Origin(OriginKind::Web));
    }

    private static function id(TestPrincipal $principal): PrincipalId
    {
        \assert(null !== $principal->principalId);

        return $principal->principalId;
    }
}
