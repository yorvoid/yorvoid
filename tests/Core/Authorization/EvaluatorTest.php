<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\PolicyElement;
use YorvoID\Core\Authorization\Reason;
use YorvoID\Core\Authorization\Remedy;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\WaitRemedy;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StoreUnreachable;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Authorization\Fixtures\StubForbid;
use YorvoID\Tests\Core\Authorization\Fixtures\StubPermit;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;

/**
 * The evaluator decides by 06 §3's table, first row that applies (the model's fixtures check every
 * combination: PrecedenceFixturesTest); here, what's around it.
 */
final class EvaluatorTest extends TestCase
{
    /**
     * @return iterable<string, array{list<PolicyElement>, Truth, Verdict, list<IndeterminateReason>}>
     */
    public static function rows(): iterable
    {
        $holds = Truth::holds('yes');
        $fails = Truth::fails('no');
        $unknown = Truth::unknown(IndeterminateReason::MissingData, 'no data');

        yield '1: a forbid that holds outweighs everything' => [[new StubForbid('f', $holds), new StubPermit('p', $holds)], $unknown, Verdict::Deny, []];
        yield '2: an unknown forbid can\'t be ruled out' => [[new StubForbid('f', $unknown), new StubPermit('p', $holds)], $fails, Verdict::Indeterminate, [IndeterminateReason::UnknownForbid, IndeterminateReason::MissingData]];
        yield '3: invalid authority' => [[new StubForbid('f', $fails), new StubPermit('p', $holds)], $fails, Verdict::Deny, []];
        yield '4: unknown authority' => [[new StubPermit('p', $holds)], Truth::unknown(IndeterminateReason::StoreUnreachable, 'down'), Verdict::Indeterminate, [IndeterminateReason::StoreUnreachable]];
        yield '5: a permit that holds' => [[new StubForbid('f', $fails), new StubPermit('p', $holds), new StubPermit('q', $unknown)], $holds, Verdict::Permit, []];
        yield '6: an unknown permit' => [[new StubPermit('p', $fails), new StubPermit('q', $unknown)], $holds, Verdict::Indeterminate, [IndeterminateReason::MissingData]];
        yield '7: otherwise, deny' => [[new StubPermit('p', $fails)], $holds, Verdict::Deny, []];
        yield '7: no element at all' => [[], $holds, Verdict::Deny, []];
    }

    /**
     * @param list<PolicyElement>       $elements
     * @param list<IndeterminateReason> $why
     */
    #[DataProvider('rows')]
    public function testDecidesByTheFirstRowThatApplies(array $elements, Truth $authority, Verdict $verdict, array $why): void
    {
        $decision = $this->decide($elements, $authority);

        self::assertSame($verdict, $decision->verdict);
        self::assertSame($why, $decision->indeterminate);
        self::assertSame(Verdict::Permit === $verdict, $decision->permits());
    }

    public function testEveryElementIsEvaluatedAndTheOrderChangesNothing(): void
    {
        $elements = [
            'a' => new StubPermit('a', Truth::holds('a holds')),
            'b' => new StubPermit('b', Truth::fails('b fails')),
            'c' => new StubForbid('c', Truth::fails('c fails')),
            'd' => new StubPermit('d', Truth::holds('d holds')),
        ];
        $first = $this->decide(array_values($elements));
        $reversed = $this->decide(array_reverse(array_values($elements)));

        self::assertEquals($first, $reversed);
        self::assertSame('a holds', $first->reason, 'The element first by name leads.');
        self::assertSame(['a', 'b', 'c', 'd', 'authority'], array_map(static fn (Reason $reason): string => $reason->source, $first->reasons));
        self::assertSame(['permit', 'permit', 'forbid', 'permit', 'authority'], array_map(static fn (Reason $reason): string => $reason->effect, $first->reasons));

        foreach ($elements as $element) {
            self::assertSame(2, $element->evaluated, 'An element wasn\'t evaluated.');
        }
    }

    public function testAnElementNotAboutTheRequestIsLeftOut(): void
    {
        $permit = new StubPermit('p', Truth::holds('yes'), matches: false);
        $forbid = new StubForbid('f', Truth::holds('yes'), matches: false);
        $decision = $this->decide([$permit, $forbid]);

        self::assertSame(Verdict::Deny, $decision->verdict);
        self::assertSame('No permit applies to "acme.badge.issue".', $decision->reason);
        self::assertSame(['authority'], array_map(static fn (Reason $reason): string => $reason->source, $decision->reasons));
        self::assertSame(0, $permit->evaluated + $forbid->evaluated);
    }

    public function testWhatThrowsIsUnknownNeverHolding(): void
    {
        $failure = new \RuntimeException('broken');

        foreach ([
            'a permit\'s condition' => [[new StubPermit('p', $failure)], [IndeterminateReason::EvaluationError]],
            'a permit\'s target' => [[new StubPermit('p', Truth::holds('yes'), matches: $failure)], [IndeterminateReason::EvaluationError]],
            'a forbid\'s condition' => [[new StubForbid('f', $failure), new StubPermit('p', Truth::holds('yes'))], [IndeterminateReason::UnknownForbid, IndeterminateReason::EvaluationError]],
            'a store' => [[new StubPermit('p', new StoreUnreachable('The database didn\'t answer.'))], [IndeterminateReason::StoreUnreachable]],
        ] as $what => [$elements, $why]) {
            $decision = $this->decide($elements);

            self::assertSame(Verdict::Indeterminate, $decision->verdict, $what);
            self::assertSame($why, $decision->indeterminate, $what);
        }

        $decision = $this->decide([new StubPermit('p', Truth::holds('yes'))], new \LogicException('lost'));
        self::assertSame([IndeterminateReason::EvaluationError], $decision->indeterminate, 'The authority check threw.');
        self::assertSame('The actor\'s authority couldn\'t be established: LogicException: lost', $decision->reason);

        $decision = $this->decide([new StubPermit('p', new StoreUnreachable('The database didn\'t answer.'))]);
        self::assertSame('A permit couldn\'t be evaluated: The database didn\'t answer.', $decision->reason);
    }

    public function testAMistakeInTheSetupIsShownNotDecidedAround(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('misconfigured');

        $this->decide([new StubPermit('p', new IntegratorError('misconfigured', 'Fix it.', 'concepts/authorization.md'))]);
    }

    public function testAPermitSpeaksOnlyForTheWaysTheOperationIsAuthorizedBy(): void
    {
        $operatorOnly = new StubPermit('operator', Truth::holds('yes'), AuthorizedBy::Operator);
        $decision = $this->decide([$operatorOnly]);

        self::assertSame(Verdict::Deny, $decision->verdict);
        self::assertSame(0, $operatorOnly->evaluated);
        self::assertSame(['authority'], array_map(static fn (Reason $reason): string => $reason->source, $decision->reasons));
    }

    public function testThePermitsBasisIsTheFirstTheOperationNames(): void
    {
        $elements = [new StubPermit('by-ability', Truth::holds('ability'), AuthorizedBy::Ability), new StubPermit('by-operator', Truth::holds('operator'), AuthorizedBy::Operator)];

        $ability = $this->decide($elements, authorizedBy: [AuthorizedBy::Ability, AuthorizedBy::Operator]);
        $operator = $this->decide($elements, authorizedBy: [AuthorizedBy::Operator, AuthorizedBy::Ability]);

        self::assertSame([AuthorizedBy::Ability, 'ability'], [$ability->basis, $ability->reason]);
        self::assertSame([AuthorizedBy::Operator, 'operator'], [$operator->basis, $operator->reason]);
    }

    public function testRemediesComeOnlyWithADenyForWantOfEvidence(): void
    {
        $stepUp = new StepUpRemedy(Duration::minutes(5), operation: 'acme.badge.issue');
        $wait = new WaitRemedy(new \DateTimeImmutable('2026-11-08 12:00:00 UTC'));
        $wanting = [new StubPermit('p', Truth::fails('Prove it again.', $stepUp)), new StubPermit('q', Truth::fails('Prove it again, and wait.', new StepUpRemedy(Duration::minutes(5), operation: 'acme.badge.issue'), $wait))];

        $denied = $this->decide($wanting);
        self::assertSame(Verdict::Deny, $denied->verdict);
        self::assertSame([$stepUp, $wait], $denied->remedies, 'Each remedy once, as first asked.');

        // Remedies are told apart by name and parameters together.
        $tomorrow = new WaitRemedy(new \DateTimeImmutable('2026-11-09 12:00:00 UTC'));
        $sameParameters = new class implements Remedy {
            public function name(): string
            {
                return 'approval';
            }

            public function parameters(): array
            {
                return ['until' => '2026-11-08T12:00:00+00:00'];
            }
        };
        $sameName = new class implements Remedy {
            public function name(): string
            {
                return 'wait';
            }

            public function parameters(): array
            {
                return [];
            }
        };
        self::assertSame([$wait, $tomorrow, $sameParameters, $sameName], $this->decide([new StubPermit('p', Truth::fails('Wait.', $wait, $tomorrow, $sameParameters, $sameName))])->remedies);
        self::assertSame('Prove it again.', $denied->reason);

        self::assertSame([], $this->decide([...$wanting, new StubForbid('f', Truth::holds('Never.'))])->remedies, 'A forbid holds whatever the evidence.');
        self::assertSame([], $this->decide($wanting, Truth::fails('Suspended.'))->remedies, 'Invalid authority isn\'t fixed by evidence.');
        self::assertSame([], $this->decide([new StubPermit('p', Truth::fails('no'))])->remedies);
    }

    public function testAnUnknownForbidSaysWhenNothingCouldPermitAnyway(): void
    {
        $forbid = new StubForbid('f', Truth::unknown(IndeterminateReason::ConflictingRecords, 'Two records disagree.'));

        self::assertSame('A forbid couldn\'t be ruled out: Two records disagree.', $this->decide([$forbid, new StubPermit('p', Truth::holds('yes'))])->reason);
        self::assertSame('A forbid couldn\'t be ruled out: Two records disagree.', $this->decide([$forbid, new StubPermit('p', Truth::unknown(IndeterminateReason::MissingData, '?'))])->reason);
        self::assertSame('A forbid couldn\'t be ruled out: Two records disagree. (refused regardless: nothing could permit it)', $this->decide([$forbid, new StubPermit('p', Truth::fails('no'))])->reason);
        self::assertSame('A forbid couldn\'t be ruled out: Two records disagree. (refused regardless: nothing could permit it)', $this->decide([$forbid, new StubPermit('p', Truth::holds('yes'))], Truth::fails('Suspended.'))->reason);
    }

    public function testIndeterminateIsLoggedAsAWarning(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string, array<mixed>}> */
            public array $logs = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = [$level, (string) $message, $context];
            }
        };
        $evaluator = new Evaluator([new StubPermit('p', Truth::unknown(IndeterminateReason::MissingData, 'no data'))], new StubAuthority(Truth::holds('valid')), logger: $logger);

        $evaluator->decide(self::operation(), self::command(), self::actor(), new Origin(OriginKind::Web));
        new Evaluator([new StubPermit('p', Truth::fails('no'))], new StubAuthority(Truth::holds('valid')), logger: $logger)->decide(self::operation(), self::command(), self::actor(), new Origin(OriginKind::Web));
        Evaluator::builtIn(new StubAuthority(Truth::unknown(IndeterminateReason::StoreUnreachable, 'down')), logger: $logger)->decide(self::operation(), self::command(), self::actor(), new Origin(OriginKind::Web));

        self::assertSame([
            ['warning', 'YorvoID couldn\'t decide whether to run "{operation}": {reason}', ['operation' => 'acme.badge.issue', 'reason' => 'A permit couldn\'t be evaluated: no data', 'indeterminate' => ['missing_data']]],
            ['warning', 'YorvoID couldn\'t decide whether to run "{operation}": {reason}', ['operation' => 'acme.badge.issue', 'reason' => 'The actor\'s authority couldn\'t be established: down', 'indeterminate' => ['store_unreachable']]],
        ], $logger->logs, 'Only Indeterminate is logged, also with the built-in permits.');
    }

    public function testEveryElementReadsTheSameMoment(): void
    {
        $clock = new MockClock('2026-11-07 18:00:00 UTC');
        /** @var \ArrayObject<int, \DateTimeImmutable> $seen */
        $seen = new \ArrayObject();
        $element = new readonly class($seen) implements PermitElement {
            /** @param \ArrayObject<int, \DateTimeImmutable> $seen */
            public function __construct(private \ArrayObject $seen)
            {
            }

            public function name(): string
            {
                return 'clock';
            }

            public function basis(): AuthorizedBy
            {
                return AuthorizedBy::Ability;
            }

            public function matches(Request $request): bool
            {
                $this->seen[] = $request->at;

                return true;
            }

            public function evaluate(Request $request): Truth
            {
                $this->seen[] = $request->at;

                return Truth::holds('yes');
            }
        };

        new Evaluator([$element], new StubAuthority(Truth::holds('valid')), $clock)->decide(self::operation(), self::command(), self::actor(), new Origin(OriginKind::Web));

        self::assertEquals([new \DateTimeImmutable('2026-11-07 18:00:00 UTC'), new \DateTimeImmutable('2026-11-07 18:00:00 UTC')], $seen->getArrayCopy());
    }

    public function testRefusesAPolicyThatDoesntFit(): void
    {
        $both = new readonly class implements PermitElement, ForbidElement {
            public function name(): string
            {
                return 'both';
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
                return Truth::holds('yes');
            }
        };
        $neither = new readonly class implements PolicyElement {
            public function name(): string
            {
                return 'neither';
            }

            public function matches(Request $request): bool
            {
                return true;
            }

            public function evaluate(Request $request): Truth
            {
                return Truth::holds('yes');
            }
        };

        foreach ([
            'must be a permit or a forbid' => [$both],
            'The policy element "neither" must be a permit or a forbid.' => [$neither],
            'Two policy elements are named "p".' => [new StubPermit('p', Truth::holds('yes')), new StubForbid('p', Truth::fails('no'))],
            'The permit "consequence" authorizes by Consequence.' => [new StubPermit('consequence', Truth::holds('yes'), AuthorizedBy::Consequence)],
        ] as $message => $elements) {
            try {
                new Evaluator($elements, new StubAuthority(Truth::holds('valid')));
                self::fail(\sprintf('A policy was built that %s', $message));
            } catch (IntegratorError $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }

    /**
     * @param list<PolicyElement> $elements
     * @param list<AuthorizedBy>  $authorizedBy
     */
    private function decide(array $elements, Truth|\Throwable|null $authority = null, array $authorizedBy = [AuthorizedBy::Ability]): Decision
    {
        $evaluator = new Evaluator($elements, new StubAuthority($authority ?? Truth::holds('valid')));

        return $evaluator->decide(self::operation($authorizedBy), self::command(), self::actor(), new Origin(OriginKind::Web));
    }

    /**
     * @param list<AuthorizedBy> $authorizedBy
     */
    private static function operation(array $authorizedBy = [AuthorizedBy::Ability]): OperationDescription
    {
        \assert([] !== $authorizedBy);

        return new OperationDescription('acme.badge.issue', 'Issue a badge', $authorizedBy, 'acme.badges.issue', null, IssueBadge::class, []);
    }

    private static function command(): IssueBadge
    {
        return new IssueBadge(new Realm('acme'), new PrincipalId(new UuidV7()));
    }

    private static function actor(): Member
    {
        return new Member(new Realm('acme'), new PrincipalId(new UuidV7()));
    }
}
