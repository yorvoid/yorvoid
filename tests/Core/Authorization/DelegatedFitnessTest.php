<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use YorvoID\Core\Authorization\DelegatedScope;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\MatchesSelectors;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\ProvesActors;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResolvesRootAnchors;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Selector\Atom;
use YorvoID\Core\Authorization\Selector\AttributeIs;
use YorvoID\Core\Authorization\Selector\IdIn;
use YorvoID\Core\Authorization\Selector\RelatedTo;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Authorization\StepUpCheck;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Authorization\ValidatesChains;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\ActsUnderDelegation;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsPrincipal;
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
        $own = $this->evaluator($chains)->decideAbility($claiming, 'acme.read', $this->realm, null, new Origin(OriginKind::Web));
        self::assertSame([IndeterminateReason::UnprovenActor], $own->indeterminate, 'An actor class of its own.');
        self::assertStringContainsString('Only UnderDelegation acts under a delegation', $own->reason);

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
     * Matchers from several sources, e.g. a generator of each package's: every one is asked, whatever
     * their keys.
     */
    public function testEveryMatcherIsAskedWhateverItsKey(): void
    {
        $holds = static fn (string $kind): MatchesSelectors => new class($kind) implements MatchesSelectors {
            public function __construct(private string $kind)
            {
            }

            public function answers(Atom $atom): bool
            {
                return $atom instanceof $this->kind;
            }

            public function match(Atom $atom, Request $request): Truth
            {
                return Truth::holds('It does.');
            }
        };
        $sources = (static function () use ($holds): \Generator {
            yield from [$holds(AttributeIs::class)];
            yield from [$holds(RelatedTo::class)];
        })();
        $scope = $this->scope(['acme.read'], new Selector('page', [new AttributeIs('status', 'draft'), new RelatedTo('editor', new ResourceRef('principal', 'ana'))]));

        self::assertSame(Verdict::Permit, $this->ask(new Evaluator([new RootReads($this->root)], new StubAuthority(Truth::holds('valid')), new MockClock(), actors: [new TrustedActors()], chains: new FixedChains($scope), selectors: $sources), 'acme.read')->verdict);
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

    /**
     * The root is judged on the same request, so its basis is the request's: a permit on another basis
     * isn't weighed for the root, and the delegated path refuses one all the same, never permitting on a
     * basis nothing decided.
     */
    public function testARootPermittedOnABasisTheRequestDoesntHavePermitsNothing(): void
    {
        $decision = new Evaluator([new RootReads($this->root, basis: AuthorizedBy::Operator)], new StubAuthority(Truth::holds('valid')), new MockClock(), actors: [new TrustedActors()], chains: new FixedChains($this->scope(['acme.read'])))->decideAbility(new UnderDelegation($this->agent, $this->leaf), 'acme.read', $this->realm, new ResourceRef('page', 'p-1'), new Origin(OriginKind::Web));

        self::assertSame(Verdict::Deny, $decision->verdict, 'Not weighed for the root, so nothing for the delegate.');
        self::assertStringContainsString('Its root may not now', $decision->reason);
    }

    public function testNothingIsDoneOnTheRootsOwnAccount(): void
    {
        $evaluator = $this->evaluator(new FixedChains($this->scope(['acme.badge.issue'])));
        $operation = new OperationDescription('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Ability], 'acme.read', null, IssueBadge::class, []);
        $decision = $evaluator->decide($operation, new IssueBadge($this->realm, $this->root), new UnderDelegation($this->agent, $this->leaf), new Origin(OriginKind::Web));

        self::assertSame(Verdict::Deny, $decision->verdict);
        self::assertStringContainsString('root\'s own account', $decision->reason);
    }

    /**
     * A forbid on the request refuses it under a delegation as for anyone; one about something else, or
     * that doesn't hold, doesn't; elements of other kinds aren't forbids. Elements are weighed by name:
     * the permit first, then a forbid about something else.
     */
    public function testAForbidOnTheRequestRefusesItUnderADelegation(): void
    {
        $elements = [new RootReads($this->root), new Forbids('acme.x_elsewhere', false, Truth::holds('It would forbid.')), new Forbids('acme.x_not_now', true, Truth::fails('Not now.')), new Forbids('acme.x_never', true, Truth::holds('Never.'))];
        $decision = $this->ask(new Evaluator($elements, new StubAuthority(Truth::holds('valid')), new MockClock(), actors: [new TrustedActors()], chains: new FixedChains($this->scope(['acme.read']))), 'acme.read');

        self::assertSame(Verdict::Deny, $decision->verdict);
        self::assertSame(['acme.x_never', 'acme.x_not_now'], array_values(array_filter(array_map(static fn (\YorvoID\Core\Authorization\Reason $reason): string => $reason->source, $decision->reasons), static fn (string $name): bool => str_starts_with($name, 'acme.x_'))), 'Each forbid about the request is weighed.');
        self::assertSame(Verdict::Permit, $this->ask($this->evaluator(new FixedChains($this->scope(['acme.read']))), 'acme.read')->verdict, 'Without them.');
    }

    /**
     * Whether the root may couldn't be established: unknown, with its reason, for a decision under a
     * delegation and for passing one on.
     */
    public function testARootThatCantBeDecidedIsUnknownWithItsReason(): void
    {
        $root = new Evaluator([new RootReads($this->root, unknown: true)], new StubAuthority(Truth::holds('valid')), new MockClock(), actors: [new TrustedActors()], chains: new FixedChains($this->scope(['acme.read'])));

        $truth = $root->rootHolds($this->root, 'acme.read', $this->realm, null, new Origin(OriginKind::Web));
        self::assertSame([TruthValue::Unknown, IndeterminateReason::MissingData], [$truth->value, $truth->reason]);
        self::assertStringStartsWith('Whether the root holds "acme.read" couldn\'t be established', $truth->detail);

        $decision = $this->ask($root, 'acme.read');
        self::assertSame([Verdict::Indeterminate, [IndeterminateReason::MissingData]], [$decision->verdict, $decision->indeterminate]);
        self::assertSame([], array_values(array_filter($decision->reasons, static fn (\YorvoID\Core\Authorization\Reason $reason): bool => 'permit' === $reason->effect)), 'No permit made of a root not permitted.');
        self::assertStringContainsString('Whether its root may now couldn\'t be established', $decision->reason);
    }

    /**
     * The request within the leaf's scope: its purpose among the scope's, each condition after the IDs met.
     */
    public function testARequestOutsideThePurposeOrAConditionIsOutside(): void
    {
        $purposed = new DelegatedScope($this->leaf, $this->agent->principalId ?? throw new \LogicException(), $this->root, ['acme.read'], new Selector('page'), ['triage'], null, null, [$this->leaf]);
        $evaluator = $this->evaluator(new FixedChains($purposed));
        $ask = fn (?string $purpose): Decision => $evaluator->decideAbility(new UnderDelegation($this->agent, $this->leaf, $purpose), 'acme.read', $this->realm, new ResourceRef('page', 'p-1'), new Origin(OriginKind::Web));

        self::assertSame(Verdict::Permit, $ask('triage')->verdict);
        self::assertStringContainsString('another purpose', $ask('billing')->reason);

        $never = new class implements MatchesSelectors {
            public function answers(Atom $atom): bool
            {
                return $atom instanceof RelatedTo;
            }

            public function match(Atom $atom, Request $request): Truth
            {
                return Truth::fails('Not related.');
            }
        };
        $conditioned = $this->scope(['acme.read'], new Selector('page', [new IdIn(['p-1']), new RelatedTo('editor', new ResourceRef('principal', 'ana'))]));
        $refused = $this->ask(new Evaluator([new RootReads($this->root)], new StubAuthority(Truth::holds('valid')), new MockClock(), actors: [new TrustedActors()], chains: new FixedChains($conditioned), selectors: [$never]), 'acme.read');
        self::assertStringContainsString('the resource doesn\'t meet its conditions', $refused->reason, 'The condition after the IDs.');
    }

    public function testAProofNothingChecksIsSaidSo(): void
    {
        $unchecked = $this->ask($this->evaluator(new FixedChains($this->scope(['acme.read'], stepUp: new StepUpRemedy(Duration::minutes(5))))), 'acme.read');

        self::assertSame(Verdict::Indeterminate, $unchecked->verdict);
        self::assertStringContainsString('Nothing checks the proof the delegation asks.', $unchecked->reason);
    }

    /**
     * A command about a principal is on the root's own account only when it's the root.
     */
    public function testACommandAboutAnotherPrincipalIsntOnTheRootsOwnAccount(): void
    {
        $evaluator = $this->evaluator(new FixedChains($this->scope(['acme.note'])));
        $operation = new OperationDescription('acme.note', 'Note about someone', [AuthorizedBy::Ability], 'acme.read', null, NoteAbout::class, []);
        $about = fn (PrincipalId $principal): Decision => $evaluator->decide($operation, new NoteAbout($this->realm, $principal), new UnderDelegation($this->agent, $this->leaf), new Origin(OriginKind::Web));

        self::assertStringContainsString('root\'s own account', $about($this->root)->reason);
        self::assertStringNotContainsString('root\'s own account', $about(new PrincipalId(Ids::next()))->reason);
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
        private AuthorizedBy $basis = AuthorizedBy::Ability,
        private bool $unknown = false,
    ) {
    }

    public function name(): string
    {
        return 'acme.root_reads';
    }

    public function basis(): AuthorizedBy
    {
        return $this->basis;
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

        if ($this->unknown) {
            return Truth::unknown(IndeterminateReason::MissingData, 'Whether the root reads isn\'t known.');
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

/**
 * A forbid by name: about the request or not, and what it says when it is.
 */
final readonly class Forbids implements ForbidElement
{
    public function __construct(
        private string $name,
        private bool $about,
        private Truth $truth,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function matches(Request $request): bool
    {
        return $this->about;
    }

    public function evaluate(Request $request): Truth
    {
        return $this->truth;
    }
}

/**
 * @implements Command<null>
 */
final readonly class NoteAbout implements Command, ConcernsPrincipal
{
    public function __construct(
        public Realm $realm,
        private PrincipalId $about,
    ) {
    }

    public function principal(): PrincipalId
    {
        return $this->about;
    }
}
