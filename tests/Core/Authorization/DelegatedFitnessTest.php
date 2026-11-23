<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use YorvoID\Core\Authorization\DelegatedScope;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\MatchesSelectors;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\ProvesActors;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResolvesRootAnchors;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Selector\Atom;
use YorvoID\Core\Authorization\Selector\AttributeIs;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Authorization\StepUpCheck;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\ValidatesChains;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\ActsUnderDelegation;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\UnderDelegation;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Testing\Ids;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Support\TrustedActors;

/**
 * Core's side of a delegated decision, against fakes (D-47 R1, D1, D2): whatever the chain validator, a
 * prover or a selector matcher says, a request under a delegation is never permitted more than its root
 * is now, and only core's UnderDelegation acts under one.
 */
final class DelegatedFitnessTest extends TestCase
{
    private Realm $realm;

    private PrincipalId $root;

    private Member $agent;

    private DelegationId $leaf;

    protected function setUp(): void
    {
        $this->realm = new Realm('acme');
        $this->root = new PrincipalId(Ids::next());
        $this->agent = new Member($this->realm, new PrincipalId(Ids::next()));
        $this->leaf = new DelegationId(Ids::next());
    }

    public function testAScopeThatClaimsWhatTheRootLacksPermitsNothing(): void
    {
        $evaluator = $this->evaluator(new FixedChains($this->scope(['acme.read', 'acme.write'])));

        self::assertSame(Verdict::Permit, $this->ask($evaluator, 'acme.read')->verdict, 'The root may read.');
        $refused = $this->ask($evaluator, 'acme.write');
        self::assertSame(Verdict::Deny, $refused->verdict, 'The root may not write: a chain never adds an ability the root lacks.');
        self::assertStringContainsString('Its root may not now', $refused->reason);
    }

    public function testAValidatorAnsweringForAnotherDelegateOrLeafIsntBelieved(): void
    {
        foreach (['another delegate' => $this->scope(['acme.read'], delegate: new PrincipalId(Ids::next())), 'another leaf' => $this->scope(['acme.read'], leaf: new DelegationId(Ids::next()))] as $case => $scope) {
            $decision = $this->ask($this->evaluator(new FixedChains($scope)), 'acme.read');
            self::assertSame([Verdict::Indeterminate, [IndeterminateReason::ConflictingRecords]], [$decision->verdict, $decision->indeterminate], $case);
        }
    }

    public function testOnlyCoresUnderDelegationActsUnderOne(): void
    {
        $chains = new FixedChains($this->scope(['acme.read']));
        $claiming = new class($this->realm, $this->agent->principalId, $this->leaf) implements ActsUnderDelegation {
            public function __construct(public ?Realm $realm, public ?PrincipalId $principalId, public DelegationId $delegation)
            {
            }
        };
        self::assertSame([IndeterminateReason::UnprovenActor], $this->evaluator($chains)->decideAbility($claiming, 'acme.read', $this->realm, null, new Origin(OriginKind::Web))->indeterminate, 'An actor class of its own.');

        // A prover that makes an actor act under a delegation: that would skip its chain.
        $prover = new class($this->leaf) implements ProvesActors {
            public function __construct(private DelegationId $leaf)
            {
            }

            public function proves(Actor $actor): bool
            {
                return $actor instanceof Member;
            }

            public function prove(Actor $actor): Actor
            {
                return new UnderDelegation($actor, $this->leaf);
            }
        };
        $evaluator = new Evaluator([new RootReads($this->root)], new StubAuthority(Truth::holds('valid')), new MockClock(), actors: [$prover], chains: $chains);
        self::assertSame([IndeterminateReason::UnprovenActor], $evaluator->decideAbility($this->agent, 'acme.read', $this->realm, null, new Origin(OriginKind::Web))->indeterminate);
    }

    public function testWithoutAValidatorADelegatedActorDecidesNothing(): void
    {
        $decision = new Evaluator([new RootReads($this->root)], new StubAuthority(Truth::holds('valid')), new MockClock(), actors: [new TrustedActors()])->decideAbility(new UnderDelegation($this->agent, $this->leaf), 'acme.read', $this->realm, null, new Origin(OriginKind::Web));

        self::assertSame([IndeterminateReason::UnprovenActor], $decision->indeterminate);
    }

    /**
     * Q1: a matcher that says yes to everything still can't give what the root lacks.
     */
    public function testAMatcherThatAlwaysHoldsGivesNothingTheRootLacks(): void
    {
        $always = new class implements MatchesSelectors {
            public function answers(Atom $atom): bool
            {
                return true;
            }

            public function match(Atom $atom, Request $request): Truth
            {
                return Truth::holds('Always.');
            }
        };
        $scope = $this->scope(['acme.read', 'acme.write'], new Selector('page', [new AttributeIs('status', 'draft')]));
        $evaluator = new Evaluator([new RootReads($this->root)], new StubAuthority(Truth::holds('valid')), new MockClock(), actors: [new TrustedActors()], chains: new FixedChains($scope), selectors: [$always]);

        self::assertSame(Verdict::Permit, $this->ask($evaluator, 'acme.read')->verdict);
        self::assertSame(Verdict::Deny, $this->ask($evaluator, 'acme.write')->verdict);
    }

    /**
     * M3-1's extended configuration: the anchor resolver is asked at every use, so a chain permitted a
     * moment ago is refused once it denies, with no version changed.
     */
    public function testTheAnchorResolverIsAskedAtEveryUse(): void
    {
        $anchors = new class implements ResolvesRootAnchors {
            public ?Truth $answer = null;

            public function resolve(DelegatedScope $scope, Request $request): ?Truth
            {
                return $this->answer;
            }
        };
        $evaluator = new Evaluator([new RootReads($this->root)], new StubAuthority(Truth::holds('valid')), new MockClock(), actors: [new TrustedActors()], chains: new FixedChains($this->scope(['acme.read'])), anchors: $anchors);

        self::assertSame(Verdict::Permit, $this->ask($evaluator, 'acme.read')->verdict);
        $anchors->answer = Truth::fails('The anchor was handed over.');
        self::assertSame(Verdict::Deny, $this->ask($evaluator, 'acme.read')->verdict, 'Asked again, never cached.');
    }

    /**
     * D2: the root's evidence can't be shown under a delegation, and none of its remedies is passed on;
     * the delegation's own proof is shown by the actor that authenticated.
     */
    public function testEvidenceUnderADelegationIsTheDelegationsOwn(): void
    {
        $remedy = new StepUpRemedy(Duration::minutes(5));
        $proofs = new class implements StepUpCheck {
            /** @var list<Actor> */
            public array $asked = [];

            public bool $shown = false;

            public function check(Actor $actor, StepUpRemedy $required): Truth
            {
                $this->asked[] = $actor;

                return $this->shown ? Truth::holds('Shown.') : Truth::fails('Not shown.', $required);
            }
        };
        $needsProof = new Evaluator([new RootReads($this->root, $remedy)], new StubAuthority(Truth::holds('valid')), new MockClock(), actors: [new TrustedActors()], chains: new FixedChains($this->scope(['acme.read'])), stepUp: $proofs);
        $refused = $this->ask($needsProof, 'acme.read');
        self::assertSame([Verdict::Deny, []], [$refused->verdict, $refused->remedies], 'A root permit that needs the root\'s step-up permits nothing, and asks the agent for nothing.');

        $scoped = new Evaluator([new RootReads($this->root)], new StubAuthority(Truth::holds('valid')), new MockClock(), actors: [new TrustedActors()], chains: new FixedChains($this->scope(['acme.read'], stepUp: $remedy)), stepUp: $proofs);
        $asked = $this->ask($scoped, 'acme.read');
        self::assertSame([Verdict::Deny, [$remedy]], [$asked->verdict, $asked->remedies], 'The delegation\'s proof, asked of the actor.');
        self::assertSame($this->agent->principalId, $proofs->asked[0]->principalId, 'Of the actor that authenticated.');
        $proofs->shown = true;
        self::assertSame(Verdict::Permit, $this->ask($scoped, 'acme.read')->verdict);

        $unchecked = new Evaluator([new RootReads($this->root)], new StubAuthority(Truth::holds('valid')), new MockClock(), actors: [new TrustedActors()], chains: new FixedChains($this->scope(['acme.read'], stepUp: $remedy)));
        self::assertSame(Verdict::Indeterminate, $this->ask($unchecked, 'acme.read')->verdict, 'A proof nothing can check.');
    }

    public function testNothingIsDoneOnTheRootsOwnAccount(): void
    {
        $evaluator = $this->evaluator(new FixedChains($this->scope(['acme.badge.issue'])));
        $operation = new OperationDescription('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Ability], 'acme.read', null, IssueBadge::class, []);
        $decision = $evaluator->decide($operation, new IssueBadge($this->realm, $this->root), new UnderDelegation($this->agent, $this->leaf), new Origin(OriginKind::Web));

        self::assertSame(Verdict::Deny, $decision->verdict);
        self::assertStringContainsString('root\'s own account', $decision->reason);
    }

    private function evaluator(ValidatesChains $chains): Evaluator
    {
        return new Evaluator([new RootReads($this->root)], new StubAuthority(Truth::holds('valid')), new MockClock(), actors: [new TrustedActors()], chains: $chains);
    }

    private function ask(Evaluator $evaluator, string $ability): Decision
    {
        return $evaluator->decideAbility(new UnderDelegation($this->agent, $this->leaf), $ability, $this->realm, new ResourceRef('page', 'p-1'), new Origin(OriginKind::Web));
    }

    /**
     * @param list<string> $actions
     */
    private function scope(array $actions, ?Selector $resources = null, ?PrincipalId $delegate = null, ?DelegationId $leaf = null, ?StepUpRemedy $stepUp = null): DelegatedScope
    {
        return new DelegatedScope($leaf ?? $this->leaf, $delegate ?? $this->agent->principalId ?? throw new \LogicException(), $this->root, $actions, $resources ?? new Selector('page'), null, null, $stepUp, [$this->leaf]);
    }
}

/**
 * The root, and only the root, may read; it needs a step-up for it when given one, which nothing can
 * show for it under a delegation.
 */
final readonly class RootReads implements PermitElement
{
    public function __construct(
        private PrincipalId $root,
        private ?StepUpRemedy $needs = null,
    ) {
    }

    public function name(): string
    {
        return 'acme.root_reads';
    }

    public function basis(): AuthorizedBy
    {
        return AuthorizedBy::Ability;
    }

    public function matches(Request $request): bool
    {
        return true;
    }

    public function evaluate(Request $request): Truth
    {
        if ('acme.read' !== $request->ability || true !== $request->actor->principalId?->equals($this->root)) {
            return Truth::fails('Not the root reading.');
        }

        return null === $this->needs || !$request->actor instanceof ActingPrincipal ? Truth::holds('The root reads.') : Truth::fails('A proof first.', $this->needs);
    }
}

final readonly class FixedChains implements ValidatesChains
{
    public function __construct(
        private DelegatedScope|Truth $answer,
    ) {
    }

    public function validate(DelegationId $leaf, PrincipalId $delegate, Request $request): DelegatedScope|Truth
    {
        return $this->answer;
    }
}
