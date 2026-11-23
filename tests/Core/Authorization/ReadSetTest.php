<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\PermitRecording;
use YorvoID\Core\Authorization\DecisionLog\Storage\InMemoryDecisionLogStore;
use YorvoID\Core\Authorization\DecisionScope;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\ReadSet;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\VersionSource;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Testing\Ids;
use YorvoID\Tests\Core\Authorization\Fixtures\CountingVersion;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Authorization\Fixtures\StubPermit;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Badges;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\RecordBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ScanBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ValidAuthority;
use YorvoID\Tests\Support\TrustedActors;

/**
 * What a decision read (D-44, G-3): the versions before its elements judged, which they read at, and the
 * sources that moved while they judged, read after. Nothing is kept for a request.
 */
final class ReadSetTest extends TestCase
{
    /**
     * M3FIT-03: a change that commits while the elements judge isn't recorded as read. The record keeps
     * the versions read before, and says what moved.
     */
    public function testAChangeWhileItJudgesIsRecordedAsMovedNotAsRead(): void
    {
        $policy = new CountingVersion('policy', 3);
        $relations = new CountingVersion('relations', 8);
        $permit = new class($policy) implements PermitElement {
            public function __construct(private readonly CountingVersion $policy)
            {
            }

            public function name(): string
            {
                return 'acme.bumps';
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
                // Another server changes the policy while this one judges.
                ++$this->policy->version;

                return Truth::fails('Not held.');
            }
        };
        $store = new InMemoryDecisionLogStore();
        $log = new DecisionLog($store);
        $decision = $this->evaluator([$permit], [$policy, $relations], log: $log)->decideAbility(self::member(), 'acme.pages.edit', new Realm('acme'), null, new Origin(OriginKind::Web));

        self::assertSame(Verdict::Deny, $decision->verdict, 'A refusal, which the log records.');
        self::assertEquals(new ReadSet(['policy' => 3, 'relations' => 8], ['policy'], []), $decision->read);
        self::assertSame(['policy' => 3, 'relations' => 8], $decision->versions, 'The versions are the read set\'s, read before.');
        self::assertFalse($decision->read->settled());
        $log->flush();
        $record = $store->find(new Realm('acme'), (string) $decision->id);
        self::assertNotNull($record);
        self::assertSame([['policy' => 3, 'relations' => 8], ['policy']], [$record->versions, $record->moved], 'Recorded as read before, and moved.');
    }

    public function testADecisionNothingChangedUnderIsSettled(): void
    {
        $decision = $this->evaluator([new StubPermit('acme.holds', Truth::holds('Held.'))], [new CountingVersion('policy', 1)], log: self::recordingAll())->decideAbility(self::member(), 'acme.pages.edit', new Realm('acme'), null, new Origin(OriginKind::Web));

        self::assertEquals(new ReadSet(['policy' => 1], [], []), $decision->read);
        self::assertTrue($decision->read->settled());
    }

    /**
     * A source that fails after judging, but not before, can't say it didn't move: it moved.
     */
    public function testASourceThatCantBeReadAfterwardsMoved(): void
    {
        $policy = new CountingVersion('policy', 4);
        $failing = new class($policy) implements PermitElement {
            public function __construct(private readonly CountingVersion $policy)
            {
            }

            public function name(): string
            {
                return 'acme.breaks';
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
                $this->policy->fails = true;

                return Truth::holds('Held.');
            }
        };

        $decision = $this->evaluator([$failing], [$policy], log: self::recordingAll())->decideAbility(self::member(), 'acme.pages.edit', new Realm('acme'), null, new Origin(OriginKind::Web));

        self::assertEquals(new ReadSet(['policy' => 4], ['policy'], []), $decision->read);
    }

    /**
     * The elements read at the versions read before judging: the stores read them from the scope, in its
     * realm only, and the versions themselves are read as no decision does.
     */
    public function testTheElementsReadAtTheVersionsReadBefore(): void
    {
        $scope = new DecisionScope();
        $policy = new CountingVersion('policy', 5, $scope);
        /** @var \ArrayObject<int, int|null> $seen */
        $seen = new \ArrayObject();
        $reads = new class($policy, $seen) implements PermitElement {
            /**
             * @param \ArrayObject<int, int|null> $seen
             */
            public function __construct(private readonly CountingVersion $policy, private readonly \ArrayObject $seen)
            {
            }

            public function name(): string
            {
                return 'acme.reads';
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
                ++$this->policy->version;
                $this->seen[] = $this->policy->version($request->realm);

                return Truth::holds('Held.');
            }
        };

        $decision = $this->evaluator([$reads], [$policy], $scope, self::recordingAll())->decideAbility(self::member(), 'acme.pages.edit', new Realm('acme'), null, new Origin(OriginKind::Web));

        self::assertSame([5], $seen->getArrayCopy(), 'While it judged, the version it read before.');
        self::assertEquals(new ReadSet(['policy' => 5], ['policy'], []), $decision->read, 'And what moved, read outside the scope.');
        self::assertNull($scope->version(new Realm('acme'), 'policy'), 'Nothing is kept once it\'s decided.');
    }

    /**
     * The permit carries what its decision read, for the guard to check again (M3-3); a consequence's
     * permit, its own decision's.
     */
    public function testAnOperationsPermitCarriesItsDecisionsReadSet(): void
    {
        $badges = new Badges();
        $operations = new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), Evaluator::builtIn(new ValidAuthority(), versions: [new CountingVersion('policy', 9)], actors: [new TrustedActors()]), new SecurityLog(new InMemorySecurityLogStore(), new InMemoryTransactions()), [$badges]);
        $operations->run(new IssueBadge(new Realm('acme'), new PrincipalId(Ids::next())), new Operator(), new Origin(OriginKind::Console));

        self::assertCount(2, $badges->permits, 'The issue, and the record it causes.');

        foreach ($badges->permits as $permit) {
            self::assertEquals(new ReadSet(['policy' => 9], [], []), $permit->read);
        }
    }

    public function testRequestsAboutAnotherResourceOrByAnotherActorKeepTheirExecutionAndPurpose(): void
    {
        $request = Request::forAbility('acme.pages.edit', new Realm('acme'), null, self::member(), new Origin(OriginKind::Web), new \DateTimeImmutable());
        $make = \Closure::bind(static fn (): Request => new Request($request->name, $request->realm, $request->ability, null, null, null, $request->actor, $request->origin, $request->at, null, 'run-1', 'triage'), null, Request::class);
        $withBoth = $make();

        foreach ([$withBoth->on(new ResourceRef('page', '1')), $withBoth->by(self::member())] as $derived) {
            self::assertSame(['run-1', 'triage'], [$derived->execution, $derived->purpose]);
        }
    }

    /**
     * @param list<PermitElement> $elements
     * @param list<VersionSource> $versions
     */
    private function evaluator(array $elements, array $versions, ?DecisionScope $scope = null, ?DecisionLog $log = null): Evaluator
    {
        return new Evaluator($elements, new StubAuthority(Truth::holds('valid')), new MockClock('2026-11-23 18:00:00'), versions: $versions, decisions: $log, actors: [new TrustedActors()], scope: $scope);
    }

    private static function recordingAll(): DecisionLog
    {
        return new DecisionLog(new InMemoryDecisionLogStore(), PermitRecording::All);
    }

    private static function member(): Member
    {
        return new Member(new Realm('acme'), new PrincipalId(Ids::next()));
    }
}
