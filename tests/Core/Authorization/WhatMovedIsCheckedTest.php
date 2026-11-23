<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\PermitRecording;
use YorvoID\Core\Authorization\DecisionLog\Storage\InMemoryDecisionLogStore;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Operation\ViewingAs;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Testing\Ids;
use YorvoID\Tests\Core\Authorization\Fixtures\CountingVersion;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Authorization\Fixtures\StubPermit;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Support\TrustedActors;

/**
 * When a decision's read set is checked after judging (D-44): wherever something uses the decision
 * beyond showing it. Only an ability's Permit that nothing records or acts on is left unchecked, and
 * says so (moved is null, never empty). One place in the evaluator decides it.
 */
final class WhatMovedIsCheckedTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): (DecisionLog|null), bool}>
     */
    public static function abilityPermits(): iterable
    {
        yield 'not recorded: no log' => [static fn (): ?DecisionLog => null, false];
        yield 'not recorded: the default' => [static fn (): DecisionLog => new DecisionLog(new InMemoryDecisionLogStore()), false];
        yield 'recorded: all' => [static fn (): DecisionLog => new DecisionLog(new InMemoryDecisionLogStore(), PermitRecording::All), true];
        yield 'recorded: sampled in' => [static fn (): DecisionLog => new DecisionLog(new InMemoryDecisionLogStore(), PermitRecording::Sampled, sampleRate: 1.0), true];
        yield 'not recorded: sampled out' => [static fn (): DecisionLog => new DecisionLog(new InMemoryDecisionLogStore(), PermitRecording::Sampled, sampleRate: 0.0), false];
        yield 'recorded: the ability says so' => [static fn (): DecisionLog => new DecisionLog(new InMemoryDecisionLogStore(), alwaysRecord: ['acme.pages.edit']), true];
    }

    /**
     * @param \Closure(): (DecisionLog|null) $log
     */
    #[DataProvider('abilityPermits')]
    public function testAnAbilitysPermitIsCheckedWhenItsRecorded(\Closure $log, bool $checked): void
    {
        $policy = new CountingVersion('policy', 2);
        $counted = new ReadCounter($policy);
        $decision = $this->evaluator(Truth::holds('Held.'), $counted, $log())->decideAbility(self::member(), 'acme.pages.edit', new Realm('acme'), null, new Origin(OriginKind::Web));

        self::assertSame($checked, $decision->read->checked());
        self::assertSame($checked ? [] : null, $decision->read->moved, 'Unchecked is null, never none.');
        self::assertSame($checked ? 2 : 1, $counted->reads, 'The versions are read again only to check.');
        self::assertFalse(!$checked && $decision->read->settled(), 'An unchecked read set isn\'t settled.');
    }

    public function testARefusalIsChecked(): void
    {
        $decision = $this->evaluator(Truth::fails('Not held.'), new CountingVersion('policy', 2))->decideAbility(self::member(), 'acme.pages.edit', new Realm('acme'), null, new Origin(OriginKind::Web));

        self::assertSame([], $decision->read->moved);
    }

    public function testAViewAsSomeoneElseIsChecked(): void
    {
        $decision = $this->evaluator(Truth::holds('Held.'), new CountingVersion('policy', 2))->decideAbility(new ViewingAs(self::member(), self::member()), 'acme.pages.edit', new Realm('acme'), null, new Origin(OriginKind::Web));

        self::assertSame([], $decision->read->moved);
    }

    public function testAnOperationAndItsConsequenceAreChecked(): void
    {
        $evaluator = Evaluator::builtIn(new StubAuthority(Truth::holds('valid')), commandLine: true, versions: [new CountingVersion('policy', 2)], actors: [new TrustedActors()]);
        $command = new IssueBadge(new Realm('acme'), new PrincipalId(Ids::next()));
        $operation = new OperationDescription('acme.badge.issue', 'Issue a badge', [\YorvoID\Core\Operation\AuthorizedBy::Operator], 'acme.badges.issue', null, IssueBadge::class, []);
        $decision = $evaluator->decide($operation, $command, new Operator(), new Origin(OriginKind::Console));
        $consequence = $evaluator->decideConsequence($decision, $operation, $command, new Operator(), new Origin(OriginKind::Console));

        self::assertSame([[], []], [$decision->read->moved, $consequence->read->moved]);
    }

    /**
     * B3: one place decides it. Every read set made in the evaluator is checked or unchecked by what
     * checksWhatMoved() says, and nothing else in the evaluator tells them apart.
     */
    public function testOnePlaceInTheEvaluatorDecidesWhatsChecked(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 3).'/packages/core/src/Authorization/Evaluator.php');

        self::assertSame(1, substr_count($source, 'function checksWhatMoved('));

        foreach (explode("\n", $source) as $line) {
            if (str_contains($line, 'ReadSet::between(') || str_contains($line, 'ReadSet::unchecked(')) {
                self::assertMatchesRegularExpression('/checksWhatMoved|\$checks|\$unchecked = ReadSet::unchecked\(\$before\);/', $line, 'A read set made without asking checksWhatMoved(): '.trim($line));
            }
        }

        self::assertSame(1, substr_count($source, 'new ReadSet('), 'The evaluator makes read sets only by between() and unchecked(), apart from the empty one of a decision not identified yet.');
        self::assertStringContainsString('ReadSet $read = new ReadSet()', $source);
    }

    private function evaluator(Truth $truth, \YorvoID\Core\Authorization\VersionSource $versions, ?DecisionLog $log = null): Evaluator
    {
        return new Evaluator([new StubPermit('acme.holds', $truth)], new StubAuthority(Truth::holds('valid')), new MockClock('2026-11-23 18:00:00'), versions: [$versions], decisions: $log, actors: [new TrustedActors()]);
    }

    private static function member(): Member
    {
        return new Member(new Realm('acme'), new PrincipalId(Ids::next()));
    }
}

/**
 * Counts the reads of a version source.
 */
final class ReadCounter implements \YorvoID\Core\Authorization\VersionSource
{
    public int $reads = 0;

    public function __construct(
        private readonly CountingVersion $source,
    ) {
    }

    public function name(): string
    {
        return $this->source->name();
    }

    public function version(Realm $realm): int
    {
        ++$this->reads;

        return $this->source->version($realm);
    }
}
