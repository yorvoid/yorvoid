<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Authorization\DecisionLog\PermitRecording;
use YorvoID\Core\Authorization\DecisionLog\Storage\InMemoryDecisionLogStore;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Precedence;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\WaitRemedy;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Operation\ViewingAs;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Tests\Core\Authorization\Fixtures\PerActor;
use YorvoID\Tests\Core\Authorization\Fixtures\PreloadingPermit;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;

/**
 * View-as (AUZ-023): every decision made for the viewer and the target and intersected, so it never
 * exceeds the viewer; and no operation at all.
 */
final class ViewingAsTest extends TestCase
{
    private Realm $realm;

    private Member $viewer;

    private Member $target;

    protected function setUp(): void
    {
        $this->realm = new Realm('acme');
        $this->viewer = new Member($this->realm, new PrincipalId(new UuidV7()));
        $this->target = new Member($this->realm, new PrincipalId(new UuidV7()));
    }

    public function testItActsAsTheTarget(): void
    {
        $view = new ViewingAs($this->viewer, $this->target);

        self::assertSame($this->target->principalId, $view->principalId);
        self::assertSame($this->realm, $view->realm);

        foreach ([[$view, $this->target], [$this->viewer, $view]] as [$viewer, $target]) {
            try {
                new ViewingAs($viewer, $target);
                self::fail('A view-as of a view-as was made.');
            } catch (IntegratorError $error) {
                self::assertStringStartsWith('A view-as was given another view-as.', $error->getMessage());
            }
        }
    }

    /**
     * Every pair of outcomes: a Permit only when both permit, Indeterminate when either is, and the
     * reasons and remedies of the side that decided.
     */
    public function testIntersectsTheViewersAndTheTargetsDecisions(): void
    {
        $outcomes = [
            'permit' => Truth::holds('It may.'),
            'deny' => null,
            'indeterminate' => Truth::unknown(IndeterminateReason::MissingData, 'Nobody knows.'),
        ];

        foreach ($outcomes as $byViewer => $viewer) {
            foreach ($outcomes as $byTarget => $target) {
                $decision = $this->decide([new PerActor([
                    (string) $this->viewer->principalId => $viewer ?? Truth::fails('The viewer may not.', new StepUpRemedy(Duration::minutes(5))),
                    (string) $this->target->principalId => $target ?? Truth::fails('The target may not.', new WaitRemedy(new \DateTimeImmutable('2026-11-10'))),
                ])]);
                $case = \sprintf('viewer %s, target %s', $byViewer, $byTarget);
                $expected = match (true) {
                    'permit' === $byViewer && 'permit' === $byTarget => 'permit',
                    'indeterminate' === $byViewer || 'indeterminate' === $byTarget => 'indeterminate',
                    default => 'deny',
                };
                // The target decides unless it alone may, or only the viewer couldn't be decided.
                $decidedBy = ('permit' === $byTarget && 'permit' !== $byViewer) || ('indeterminate' === $byViewer && 'indeterminate' !== $byTarget) ? 'viewer' : 'target';

                self::assertSame($expected, $decision->verdict->value, $case);
                self::assertSame('permit' === $expected, $decision->permits(), $case);
                self::assertSame($byViewer, $decision->viewer?->verdict->value, $case.': the viewer\'s own decision.');
                self::assertNull($decision->viewer->viewer, $case);

                if ('deny' === $expected) {
                    self::assertSame('viewer' === $decidedBy ? ['step_up'] : ['wait'], array_map(static fn ($remedy): string => $remedy->name(), $decision->remedies), $case.': the remedies of the side that refused, never the target\'s for the viewer.');
                    self::assertSame('viewer' === $decidedBy ? 'Viewing as someone else, the viewer may not: The viewer may not.' : 'The target may not.', $decision->reason, $case);
                }

                if ('indeterminate' === $expected) {
                    self::assertSame([IndeterminateReason::MissingData], $decision->indeterminate, $case);
                    self::assertSame('viewer' === $decidedBy ? 'Viewing as someone else, the viewer\'s own access couldn\'t be decided: A permit couldn\'t be evaluated: Nobody knows.' : 'A permit couldn\'t be evaluated: Nobody knows.', $decision->reason, $case);
                }

                self::assertSame('viewer' === $decidedBy ? $decision->viewer->reasons : $decision->reasons, $decision->reasons, $case);
                self::assertNotNull($decision->precedence, $case);
                self::assertNotNull($decision->id, $case.': identified as any other.');
            }
        }
    }

    public function testRunsNoOperation(): void
    {
        $permit = new PerActor([], AuthorizedBy::Self);
        $evaluator = new Evaluator([$permit], new StubAuthority(Truth::holds('valid')));
        $operation = new OperationDescription('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Self], null, null, IssueBadge::class, []);
        $decision = $evaluator->decide($operation, new IssueBadge($this->realm, $this->target->principalId ?? throw new \LogicException()), new ViewingAs($this->viewer, $this->target), new Origin(OriginKind::Web));

        self::assertSame(Verdict::Deny, $decision->verdict);
        self::assertSame(Precedence::ForbidHolds, $decision->precedence);
        self::assertSame('A forbid applies: A view as someone else is read-only: no operation runs for it.', $decision->reason);
        self::assertSame([ViewingAs::READ_ONLY], array_map(static fn ($reason): string => $reason->source, $decision->reasons));
        self::assertSame([], $decision->remedies);
        self::assertNull($decision->viewer, 'Neither side was asked.');
        self::assertSame(0, $permit->asked, 'Not even the target\'s own account.');
    }

    public function testABatchPreloadsBothSides(): void
    {
        $preloading = new PreloadingPermit();
        $evaluator = new Evaluator([$preloading], $preloading);
        $view = new ViewingAs($this->viewer, $this->target);
        $decisions = $evaluator->decideAbilities($view, 'acme.pages.edit', $this->realm, [[new ResourceRef('page', '1'), null], [new ResourceRef('page', '2'), null]], new Origin(OriginKind::Web));

        self::assertSame([Verdict::Permit, Verdict::Permit], array_map(static fn ($decision): Verdict => $decision->verdict, $decisions), 'Each side reads what was preloaded for it.');
        self::assertCount(2, $preloading->batches, 'The authority check and the permit, once each.');
        self::assertSame([$this->viewer, $this->target, $this->viewer, $this->target], array_map(static fn (Request $request): Actor => $request->actor, $preloading->batches[0]));
        self::assertSame(Verdict::Deny, $evaluator->decideAbility($view, 'acme.pages.edit', $this->realm, new ResourceRef('page', '1'), new Origin(OriginKind::Web))->verdict, 'Alone, nothing was preloaded.');
    }

    public function testRecordsTheViewerWithTheTarget(): void
    {
        $store = new InMemoryDecisionLogStore();
        $log = new DecisionLog($store, PermitRecording::All);
        $evaluator = new Evaluator([new PerActor([(string) $this->viewer->principalId => Truth::holds('It may.'), (string) $this->target->principalId => Truth::holds('It may.')])], new StubAuthority(Truth::holds('valid')), decisions: $log);
        $operation = new OperationDescription('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Self], null, null, IssueBadge::class, []);
        $view = new ViewingAs($this->viewer, $this->target);
        $decision = $evaluator->decideAbility($view, 'acme.pages.edit', $this->realm, null, new Origin(OriginKind::Web));
        $refused = $evaluator->decide($operation, new IssueBadge($this->realm, $this->target->principalId ?? throw new \LogicException()), $view, new Origin(OriginKind::Web));
        $alone = $evaluator->decideAbility($this->viewer, 'acme.pages.edit', $this->realm, null, new Origin(OriginKind::Web));
        $log->flush();
        $own = $store->find($this->realm, $alone->id ?? '') ?? throw new \LogicException('Not recorded.');

        $record = $store->find($this->realm, $decision->id ?? '') ?? throw new \LogicException('Not recorded.');
        self::assertSame((string) $this->target->principalId, $record->actor, 'It acts as the target.');
        self::assertSame(['actor' => (string) $this->viewer->principalId, 'verdict' => 'permit', 'elements' => [['acme.per_actor', 'permit', 'holds'], ['authority', 'authority', 'holds']]], $record->viewer);
        self::assertEquals($record, DecisionRecord::fromJson($record->json()), 'Read back as it was.');
        self::assertSame(['actor' => (string) $this->viewer->principalId, 'verdict' => null, 'elements' => []], $store->find($this->realm, $refused->id ?? '')?->viewer, 'An operation is refused before either side is asked.');
        self::assertNull($own->viewer, 'Not viewing as anyone.');
        self::assertArrayHasKey('viewer', $own->toArray(), 'Always in the canonical form.');

        foreach (['{"actor":null,"verdict":"permit","elements":[]}' => true, '"someone"' => false, '{"verdict":1,"elements":[]}' => false] as $viewer => $valid) {
            $json = str_replace('"viewer":null', '"viewer":'.$viewer, $own->json());

            try {
                self::assertSame($valid, null !== DecisionRecord::fromJson($json)->viewer);
            } catch (\UnexpectedValueException) {
                self::assertFalse($valid, $viewer);
            }
        }
    }

    /**
     * @param list<PermitElement> $elements
     */
    private function decide(array $elements): Decision
    {
        return new Evaluator($elements, new StubAuthority(Truth::holds('valid')))->decideAbility(new ViewingAs($this->viewer, $this->target), 'acme.pages.edit', $this->realm, null, new Origin(OriginKind::Web));
    }
}
