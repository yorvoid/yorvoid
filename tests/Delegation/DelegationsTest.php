<?php

declare(strict_types=1);

namespace YorvoID\Tests\Delegation;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Authorization\Selector\AttributeIs;
use YorvoID\Core\Authorization\Selector\IdIn;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Delegation\Chains;
use YorvoID\Delegation\Change\IssueDelegation;
use YorvoID\Delegation\Change\RevokeDelegation;
use YorvoID\Delegation\Change\RevokeDelegationsOf;
use YorvoID\Delegation\Delegation;
use YorvoID\Delegation\DelegationRefused;
use YorvoID\Delegation\DelegationStatus;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\TestPrincipal;

/**
 * Issuing and revoking delegations (06 §5): a delegator gives only what they hold, never what may not
 * be delegated, within the limits; a delegation passed on stays within its parent on every dimension,
 * and its parent's chain must hold now; a revocation is its delegator's, or one above it in its chain.
 */
final class DelegationsTest extends DatabaseTestCase
{
    private const string EXPIRES = '2026-11-14 20:00:00';

    #[DataProvider('implementations')]
    public function testAnEditorDelegatesWhatTheyHoldOnThePagesTheyName(string $implementation): void
    {
        $world = $this->world($implementation);
        [$ana, $bot] = $this->people($world);

        $delegation = $this->issue($world, $ana, new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish', 'acme.pages.view_drafts'], new Selector('page', [new IdIn(['p-1', 'p-2'])]), new \DateTimeImmutable(self::EXPIRES), purpose: 'release-notes', execution: 'run-1'));
        \assert($delegation instanceof Delegation);

        self::assertEquals([self::id($ana), self::id($bot), self::id($ana), 0, null], [$delegation->delegator, $delegation->delegate, $delegation->root, $delegation->depth, $delegation->parent]);
        self::assertSame(['acme.pages.publish', 'acme.pages.view_drafts'], $delegation->actions);
        self::assertEquals($delegation, $world->delegationStore?->get($world->realm, $delegation->id), 'Kept as issued.');
        self::assertSame(1, $world->delegationStore?->version($world->realm));
    }

    #[DataProvider('implementations')]
    public function testAPatternIsExpandedAgainstTheCatalogue(string $implementation): void
    {
        $world = $this->world($implementation);
        $manager = $world->principal('manager');
        $bea = $world->principal();

        $delegation = $this->issue($world, $manager, $this->to($world, $bea, ['acme.sites.*']));
        \assert($delegation instanceof Delegation);
        self::assertSame(['acme.sites.update'], $delegation->actions, 'Expanded, so an action added later isn\'t delegated.');
        self::assertSame(['acme.sites.*'], $delegation->patterns, 'As written, for people.');
        $this->issue($world, $manager, $this->to($world, $bea, ['acme.sites.update*']), expectRefusal: 'decision', case: 'not a pattern of whole segments: nobody holds it');
    }

    /**
     * @return iterable<string, array{string, string, list<string>, Selector, string}>
     */
    public static function refusals(): iterable
    {
        foreach (self::implementations() as $name => [$implementation]) {
            yield $name.': what nobody may delegate' => [$implementation, 'editor', ['yorvoid.delegations.revoke'], new Selector('delegation'), 'non_delegable'];
            yield $name.': YorvoID\'s own operation, to an agent' => [$implementation, 'admin', ['yorvoid.principal.create'], new Selector('principal'), 'non_delegable'];
            yield $name.': a strict ability, which takes a proof first' => [$implementation, 'purger', ['acme.pages.purge'], new Selector('page'), 'decision'];
            yield $name.': an action nobody declares, which nobody holds' => [$implementation, 'editor', ['acme.unknown'], new Selector('page'), 'decision'];
        }
    }

    /**
     * @param list<string> $actions
     */
    #[DataProvider('refusals')]
    public function testRefusesWhatMayNotBeDelegated(string $implementation, string $role, array $actions, Selector $resources, string $reason): void
    {
        $world = $this->world($implementation);

        // Someone who holds the strict ability, granted to their role: its strictness is what refuses.
        if ('purger' === $role) {
            $world->asOperator(new GrantAbilities($world->realm, 'editor', ['acme.pages.purge']));
            $role = 'editor';
        }

        [$ana, $bot] = $this->people($world, $role);

        $this->issue($world, $ana, new IssueDelegation($world->realm, self::id($bot), $actions, $resources, new \DateTimeImmutable(self::EXPIRES)), expectRefusal: $reason);
    }

    /**
     * AGT-011: a root delegator gives only what they hold, on the resources named; the decision refuses
     * the rest, before anything is written.
     */
    #[DataProvider('implementations')]
    public function testARootDelegatorGivesOnlyWhatTheyHold(string $implementation): void
    {
        $world = $this->world($implementation);
        $member = $world->principal('member');
        $bot = $this->agent($world, $member);

        try {
            $world->as($member, $this->to($world, $bot, ['acme.pages.publish']));
            self::fail('Delegated what they don\'t hold.');
        } catch (NotPermitted $refused) {
            self::assertStringContainsString('acme.pages.publish', $refused->decision->reason);
        }

        self::assertSame(0, $world->delegationStore?->version($world->realm), 'Nothing written.');
    }

    #[DataProvider('implementations')]
    public function testLimitsAndShapesAreRefused(string $implementation): void
    {
        $world = $this->world($implementation);
        [$ana, $bot] = $this->people($world);
        $now = new \DateTimeImmutable('2026-11-07 20:00:00');

        foreach ([
            'to oneself' => [new IssueDelegation($world->realm, self::id($ana), ['acme.pages.publish'], new Selector('page'), $now->modify('+1 day')), 'to_oneself'],
            'ends before it starts' => [new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish'], new Selector('page'), $now->modify('-1 day')), 'limits'],
            'longer than an agent may' => [new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish'], new Selector('page'), $now->modify('+31 days')), 'limits'],
            'too many IDs' => [new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish'], new Selector('page', [new IdIn(array_map(strval(...), range(1, 101)))]), $now->modify('+1 day')), 'limits'],
            'deeper than a chain may be' => [new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish'], new Selector('page'), $now->modify('+1 day'), redelegable: ['acme.pages.publish'], depth: 3), 'limits'],
            'passes on what it doesn\'t give' => [new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish'], new Selector('page'), $now->modify('+1 day'), redelegable: ['acme.pages.delete'], depth: 1), 'limits'],
            'to a suspended delegate' => [new IssueDelegation($world->realm, self::id($world->asOperator(new SuspendPrincipal($world->realm, self::id($this->agent($world, $ana))))), ['acme.pages.publish'], new Selector('page'), $now->modify('+1 day')), 'inactive_delegate'],
        ] as $case => [$command, $reason]) {
            $this->issue($world, $ana, $command, expectRefusal: $reason, case: $case);
        }
    }

    /**
     * A delegation passed on, by a person it was given to: within its parent on every dimension, its
     * parent's chain holding now; an agent passes nothing on.
     */
    #[DataProvider('implementations')]
    public function testADelegationPassedOnStaysWithinItsParent(string $implementation): void
    {
        $world = $this->world($implementation);
        [$ana] = $this->people($world);
        $bea = $world->principal();
        $bot = $this->agent($world, $bea);
        $parent = $this->issue($world, $ana, new IssueDelegation($world->realm, self::id($bea), ['acme.pages.publish', 'acme.pages.view_drafts'], new Selector('page', [new IdIn(['p-1', 'p-2'])]), new \DateTimeImmutable(self::EXPIRES), redelegable: ['acme.pages.publish'], depth: 1));
        \assert($parent instanceof Delegation);

        $child = $this->issue($world, $bea, new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish'], new Selector('page', [new IdIn(['p-1']), new AttributeIs('status', 'draft')]), new \DateTimeImmutable('2026-11-10 20:00:00'), parent: $parent->id));
        \assert($child instanceof Delegation);
        self::assertEquals([self::id($bea), self::id($ana), 1, $parent->id], [$child->delegator, $child->root, $child->depth, $child->parent]);
        self::assertTrue(new Chains($world->delegationStore ?? throw new \LogicException(), $this->principals($world))->check($child, new \DateTimeImmutable('2026-11-08 20:00:00'))->valid);

        foreach ([
            'an action it may not pass on' => new IssueDelegation($world->realm, self::id($bot), ['acme.pages.view_drafts'], new Selector('page', [new IdIn(['p-1'])]), new \DateTimeImmutable('2026-11-10 20:00:00'), parent: $parent->id),
            'other pages' => new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish'], new Selector('page', [new IdIn(['p-3'])]), new \DateTimeImmutable('2026-11-10 20:00:00'), parent: $parent->id),
            'every page' => new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish'], new Selector('page'), new \DateTimeImmutable('2026-11-10 20:00:00'), parent: $parent->id),
            'past its parent\'s end' => new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish'], new Selector('page', [new IdIn(['p-1'])]), new \DateTimeImmutable('2026-11-14 20:00:01'), parent: $parent->id),
            'further than its parent allows' => new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish'], new Selector('page', [new IdIn(['p-1'])]), new \DateTimeImmutable('2026-11-10 20:00:00'), redelegable: ['acme.pages.publish'], depth: 1, parent: $parent->id),
        ] as $case => $command) {
            $this->issue($world, $bea, $command, expectRefusal: 'outside_parent', case: $case);
        }

        $this->issue($world, $ana, new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish'], new Selector('page', [new IdIn(['p-1'])]), new \DateTimeImmutable('2026-11-10 20:00:00'), parent: $parent->id), expectRefusal: 'not_yours', case: 'by someone it wasn\'t given to');
        $this->issue($world, new TestPrincipal($world->realm, self::id($bot)), new IssueDelegation($world->realm, self::id($bea), ['acme.pages.publish'], new Selector('page', [new IdIn(['p-1'])]), new \DateTimeImmutable('2026-11-10 20:00:00'), parent: $child->id), expectRefusal: 'agent_delegator', case: 'by an agent');

        // Its parent revoked: nothing more is passed on from it.
        $world->as($ana, new RevokeDelegation($world->realm, $parent->id, 'done'));
        $this->issue($world, $bea, new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish'], new Selector('page', [new IdIn(['p-2'])]), new \DateTimeImmutable('2026-11-10 20:00:00'), parent: $parent->id), expectRefusal: 'parent_chain', case: 'its parent revoked');
        self::assertFalse(new Chains($world->delegationStore, $this->principals($world))->check($child, new \DateTimeImmutable('2026-11-08 20:00:00'))->valid, 'The child stops at its next use.');
    }

    #[DataProvider('implementations')]
    public function testARevocationIsItsDelegatorsOrAboveItsOrTheOperators(string $implementation): void
    {
        $world = $this->world($implementation);
        [$ana] = $this->people($world);
        $bea = $world->principal();
        $bot = $this->agent($world, $bea);
        $parent = $this->issue($world, $ana, new IssueDelegation($world->realm, self::id($bea), ['acme.pages.publish'], new Selector('page'), new \DateTimeImmutable(self::EXPIRES), redelegable: ['acme.pages.publish'], depth: 1));
        \assert($parent instanceof Delegation);
        $child = $this->issue($world, $bea, new IssueDelegation($world->realm, self::id($bot), ['acme.pages.publish'], new Selector('page'), new \DateTimeImmutable('2026-11-10 20:00:00'), parent: $parent->id));
        \assert($child instanceof Delegation);

        try {
            $world->as(new TestPrincipal($world->realm, self::id($bot)), new RevokeDelegation($world->realm, $parent->id, 'mine now'));
            self::fail('The delegate revoked it.');
        } catch (DelegationRefused $refused) {
            self::assertSame('not_yours', $refused->reason);
        }

        self::assertTrue($world->as($ana, new RevokeDelegation($world->realm, $child->id, 'the root, above it')));
        self::assertFalse($world->as($bea, new RevokeDelegation($world->realm, $child->id, 'again')), 'Revoked already.');
        self::assertSame(DelegationStatus::Revoked, $world->delegationStore?->get($world->realm, $child->id)?->status);
        self::assertTrue($world->asOperator(new RevokeDelegation($world->realm, $parent->id, 'the operator')));
    }

    /**
     * AGT-042: every active delegation issued by or to a principal, at once.
     */
    #[DataProvider('implementations')]
    public function testRevokesEveryDelegationOfAPrincipal(string $implementation): void
    {
        $world = $this->world($implementation);
        [$ana, $bot] = $this->people($world);
        $other = $this->agent($world, $ana);

        foreach ([$bot, $other] as $to) {
            $this->issue($world, $ana, $this->to($world, $to, ['acme.pages.publish']));
        }

        self::assertSame(2, $world->asOperator(new RevokeDelegationsOf($world->realm, self::id($ana), 'left the team')));
        self::assertSame([], $world->delegationStore?->involving($world->realm, self::id($ana)));
        self::assertSame(0, $world->asOperator(new RevokeDelegationsOf($world->realm, self::id($ana), 'again')));
    }

    private function world(string $implementation): PolicyWorld
    {
        return new PolicyWorld(stack: 'memory' === $implementation ? null : $this->stack($implementation), delegations: true);
    }

    /**
     * An editor and an agent of theirs.
     *
     * @return array{TestPrincipal, TestPrincipal}
     */
    private function people(PolicyWorld $world, string $role = 'editor'): array
    {
        $ana = $world->principal($role);

        return [$ana, $this->agent($world, $ana)];
    }

    private function agent(PolicyWorld $world, TestPrincipal $owner): TestPrincipal
    {
        return new TestPrincipal($world->realm, $world->asOperator(new CreatePrincipal($world->realm, PrincipalKind::Agent, owner: self::id($owner)))->id);
    }

    /**
     * @param list<string> $actions
     */
    private function to(PolicyWorld $world, TestPrincipal $delegate, array $actions): IssueDelegation
    {
        return new IssueDelegation($world->realm, self::id($delegate), $actions, new Selector('page'), new \DateTimeImmutable(self::EXPIRES));
    }

    /**
     * @param Command<mixed> $command
     */
    private function issue(PolicyWorld $world, TestPrincipal $by, Command $command, ?string $expectRefusal = null, string $case = ''): mixed
    {
        if (null === $expectRefusal) {
            return $world->as($by, $command);
        }

        try {
            $world->as($by, $command);
            self::fail('Issued: '.$case);
        } catch (DelegationRefused $refused) {
            self::assertSame($expectRefusal, $refused->reason, $case.': '.$refused->getMessage());
        } catch (NotPermitted $refused) {
            self::assertSame('decision', $expectRefusal, $case.': refused by the decision: '.$refused->decision->reason);
        }

        return null;
    }

    private function principals(PolicyWorld $world): \YorvoID\Core\Principal\PrincipalStore
    {
        return $world->principalStore;
    }

    private static function id(TestPrincipal|\YorvoID\Core\Principal\Principal $principal): PrincipalId
    {
        $id = $principal instanceof TestPrincipal ? $principal->principalId : $principal->id;
        \assert(null !== $id);

        return $id;
    }
}
