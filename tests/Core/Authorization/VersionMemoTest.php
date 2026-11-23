<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Authorization\DecisionLog\PermitRecording;
use YorvoID\Core\Authorization\DecisionLog\Storage\InMemoryDecisionLogStore;
use YorvoID\Core\Authorization\DecisionScope;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\VersionMemo;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Operation\ViewingAs;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Testing\Ids;
use YorvoID\Tests\Core\Authorization\Fixtures\CountingVersion;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Support\TrustedActors;

/**
 * The opt-in memo of versions, for showing (G-3 Q1): an ability's permit decided at the versions the
 * request first read, and pinned; never an operation, a view-as or a refusal.
 */
final class VersionMemoTest extends TestCase
{
    private Realm $realm;

    private DecisionScope $scope;

    private CountingVersion $policy;

    protected function setUp(): void
    {
        $this->realm = new Realm('acme');
        $this->scope = new DecisionScope();
        $this->policy = new CountingVersion('policy', 1, $this->scope);
    }

    /**
     * A revocation from another process: the same request's operation sees it, a memoized link doesn't.
     */
    public function testAnOperationSeesARevocationAMemoizedPermitDoesnt(): void
    {
        $evaluator = $this->evaluator(atVersion: 1, memo: new VersionMemo());
        self::assertSame(Verdict::Permit, $this->ability($evaluator)->verdict);

        $this->policy->version = 2;  // revoked elsewhere

        $shown = $this->ability($evaluator);
        self::assertSame(Verdict::Permit, $shown->verdict, 'The link is as old as the request.');
        self::assertTrue($shown->read->pinned);
        self::assertSame(['policy' => 1], $shown->versions);
        self::assertNull($shown->read->moved, 'Unchecked.');

        $operation = $evaluator->decide(self::operation(), new IssueBadge($this->realm, new PrincipalId(Ids::next())), new Operator(), new Origin(OriginKind::Console));
        self::assertSame(Verdict::Deny, $operation->verdict, 'The operation decides on what\'s committed now.');
        self::assertFalse($operation->read->pinned);
        self::assertSame(['policy' => 2], $operation->versions);

        self::assertFalse($this->ability($evaluator, new ViewingAs(self::member(), self::member()))->read->pinned, 'Nor a view as someone else.');
    }

    /**
     * A job-like loop that never resets the memo sees a revocation from another process once the
     * versions are older than the max age (M3FIT-01 under the memo).
     */
    public function testALongJobSeesARevocationOnceTheMaxAgePasses(): void
    {
        $clock = new MockClock('2026-11-23 18:00:00');
        $evaluator = $this->evaluator(atVersion: 1, memo: new VersionMemo($clock));
        self::assertSame(Verdict::Permit, $this->ability($evaluator)->verdict);

        $this->policy->version = 2;
        $clock->sleep(1);
        self::assertSame(Verdict::Permit, $this->ability($evaluator)->verdict, 'Within the max age, the memo\'s.');

        $clock->sleep(1);
        self::assertSame(Verdict::Deny, $this->ability($evaluator)->verdict, 'At the max age, 2 seconds by default, read again: revoked.');
    }

    public function testTheMaxAgeIsTheOneGiven(): void
    {
        $clock = new MockClock('2026-11-23 18:00:00');
        $evaluator = $this->evaluator(atVersion: 1, memo: new VersionMemo($clock, \YorvoID\Core\Duration::seconds(5)));
        self::assertSame(Verdict::Permit, $this->ability($evaluator)->verdict);

        $this->policy->version = 2;
        $clock->sleep(4);
        self::assertSame(Verdict::Permit, $this->ability($evaluator)->verdict, 'Within the 5 seconds given, the memo\'s.');

        $clock->sleep(1);
        self::assertSame(Verdict::Deny, $this->ability($evaluator)->verdict);
    }

    /**
     * A refusal is recorded and acted on: never pinned, decided again on what's committed now.
     */
    public function testARefusalIsNeverPinned(): void
    {
        $memo = new VersionMemo();
        $evaluator = $this->evaluator(atVersion: 2, memo: $memo);
        self::assertSame(Verdict::Deny, $this->ability($evaluator)->verdict, 'At version 1, the memo\'s.');

        $this->policy->version = 2;
        $decided = $this->ability($evaluator);
        self::assertSame(Verdict::Permit, $decided->verdict, 'The memo\'s refusal was decided again on what\'s committed.');
        self::assertFalse($decided->read->pinned);

        $this->policy->version = 3;
        $refused = $this->ability($evaluator);
        self::assertSame([Verdict::Deny, false, []], [$refused->verdict, $refused->read->pinned, $refused->read->moved], 'Refused on version 3, checked, not pinned.');
    }

    public function testAnIndeterminateDecisionIsNeverPinned(): void
    {
        $evaluator = $this->evaluator(atVersion: 2, memo: new VersionMemo(), unknownAt: 1);
        self::assertSame(Verdict::Indeterminate, $this->ability($evaluator)->verdict, 'At version 1, the memo\'s.');

        $this->policy->version = 2;
        $decided = $this->ability($evaluator);
        self::assertSame([Verdict::Permit, false], [$decided->verdict, $decided->read->pinned], 'Decided again on what\'s committed.');
    }

    public function testARecordedPermitSaysItsPinned(): void
    {
        $store = new InMemoryDecisionLogStore();
        $log = new DecisionLog($store, PermitRecording::All);
        $evaluator = $this->evaluator(atVersion: 1, memo: new VersionMemo(), log: $log);
        $decision = $this->ability($evaluator);
        $log->flush();
        $record = $store->find($this->realm, (string) $decision->id) ?? self::fail('Not recorded.');

        self::assertTrue($record->pinned);
        self::assertSame([], $record->attributes, 'A recorded pinned permit holds the attributes it read, here none.');
        $read = $record->toArray()['read'];
        self::assertTrue(\is_array($read) && true === ($read['pinned'] ?? null));
        self::assertTrue(DecisionRecord::fromJson($record->json())->pinned);
        self::assertSame($record->hash(), DecisionRecord::fromJson($record->json())->hash());
    }

    public function testWithoutTheMemoNothingIsPinned(): void
    {
        $evaluator = $this->evaluator(atVersion: 1);
        $this->ability($evaluator);
        $this->policy->version = 2;

        self::assertSame(Verdict::Deny, $this->ability($evaluator)->verdict);
    }

    private function ability(Evaluator $evaluator, ?\YorvoID\Core\Operation\Actor $actor = null): \YorvoID\Core\Operation\Decision
    {
        return $evaluator->decideAbility($actor ?? self::member(), 'acme.badges.issue', $this->realm, null, new Origin(OriginKind::Web));
    }

    /**
     * A permit that holds at one version of the policy only, read as the cached stores read it.
     */
    private function evaluator(int $atVersion, ?VersionMemo $memo = null, ?DecisionLog $log = null, ?int $unknownAt = null): Evaluator
    {
        $permit = new class($this->policy, $atVersion, $unknownAt) implements PermitElement {
            public function __construct(private readonly CountingVersion $policy, private readonly int $at, private readonly ?int $unknownAt)
            {
            }

            public function name(): string
            {
                return 'acme.at_version';
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
                if ($this->unknownAt === $this->policy->version($request->realm)) {
                    return Truth::unknown(IndeterminateReason::StoreUnreachable, 'Not reachable at this version.');
                }

                return $this->at === $this->policy->version($request->realm) ? Truth::holds('At its version.') : Truth::fails('Not at its version.');
            }
        };
        $operator = new class($permit) implements PermitElement {
            public function __construct(private readonly PermitElement $inner)
            {
            }

            public function name(): string
            {
                return 'acme.operator_at_version';
            }

            public function basis(): AuthorizedBy
            {
                return AuthorizedBy::Operator;
            }

            public function matches(Request $request): bool
            {
                return true;
            }

            public function evaluate(Request $request): Truth
            {
                return $this->inner->evaluate($request);
            }
        };

        return new Evaluator([$permit, $operator], new StubAuthority(Truth::holds('valid')), new MockClock('2026-11-23 18:00:00'), versions: [$this->policy], decisions: $log, actors: [new TrustedActors()], scope: $this->scope, memo: $memo);
    }

    private static function operation(): OperationDescription
    {
        return new OperationDescription('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Operator], 'acme.badges.issue', null, IssueBadge::class, []);
    }

    private static function member(): Member
    {
        return new Member(new Realm('acme'), new PrincipalId(Ids::next()));
    }
}
