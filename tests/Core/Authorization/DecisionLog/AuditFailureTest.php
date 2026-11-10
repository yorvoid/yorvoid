<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\DecisionLog;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\DecisionLog\AuditFailure;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\PermitRecording;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\AuditAlarm;
use YorvoID\Core\Log\Event\AuditFailed;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\RecordBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ScanBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\SelfService;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ValidAuthority;
use YorvoID\Tests\Support\RecordingDispatcher;
use YorvoID\Tests\Support\RecordingLogger;

/**
 * The response to audit failure (AUD-006, AU-5): in the Refuse mode, nothing is permitted without its
 * record, and in every mode a record that can't be written raises the alert, which a failing listener
 * doesn't stop or change.
 */
final class AuditFailureTest extends TestCase
{
    private Realm $realm;

    private MockClock $clock;

    private FailingDecisionLogStore $store;

    private RecordingDispatcher $events;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->realm = new Realm('acme');
        $this->clock = new MockClock('2026-11-10 18:00:00 UTC');
        $this->store = new FailingDecisionLogStore();
        $this->events = new RecordingDispatcher();
        $this->logger = new RecordingLogger();
    }

    public function testRefusingWritesEveryRecordedDecisionBeforeReturningIt(): void
    {
        $log = $this->log(AuditFailure::Refuse);
        $denied = $log->record($this->ability(), $this->decided(Decision::deny('No.')));

        self::assertSame(1, $this->store->writes, 'Nothing waits for the end of the request.');
        self::assertSame($denied->hash, $this->store->find($this->realm, (string) $denied->id)?->hash());
        self::assertSame(AuditFailure::Refuse, $log->onFailure());
        self::assertSame(AuditFailure::Continue, new DecisionLog($this->store)->onFailure(), 'Continuing is the default (MP-1).');
    }

    public function testRefusingAPermitWhoseRecordCantBeWrittenMakesItIndeterminate(): void
    {
        $log = $this->log(AuditFailure::Refuse, PermitRecording::All);
        $this->store->failing = true;
        $permit = $this->decided(self::permit());

        $refused = $log->record($this->ability(), $permit);

        self::assertSame(Verdict::Indeterminate, $refused->verdict);
        self::assertSame([IndeterminateReason::AuditUnavailable], $refused->indeterminate);
        self::assertSame('its record couldn\'t be written', $refused->reason);
        self::assertSame([$permit->id, $permit->at, $permit->versions], [$refused->id, $refused->at, $refused->versions], 'It keeps its identity.');
        self::assertNull($refused->hash, 'It isn\'t on record.');
        self::assertFalse($refused->permits());

        $failed = $this->events->of(AuditFailed::class);
        self::assertCount(1, $failed);
        self::assertSame([AuditFailed::DECISION_LOG, 'acme', 1, true, 'The database went away.'], [$failed[0]->log, $failed[0]->realm?->name, $failed[0]->records, $failed[0]->refused, $failed[0]->error->getMessage()]);
        $critical = $this->logger->of('critical');
        self::assertCount(1, $critical);
        self::assertSame(['decision log', ', and refused what they were for', 'acme', 'The database went away.'], [$critical[0][2]['log'], $critical[0][2]['refused'], $critical[0][2]['realm'], $critical[0][2]['message']]);
        self::assertSame($failed[0]->error, $critical[0][2]['exception'], 'The logger gets the exception, with its trace.');
    }

    public function testRefusingAPermitOfflineWhoseRecordCantBeWrittenMakesItIndeterminate(): void
    {
        $this->store->failing = true;
        $offline = new Decision(Verdict::PermitOffline, AuthorizedBy::Ability, 'offline', [], [], [], (string) new UuidV7(), $this->clock->now());

        self::assertSame(Verdict::Indeterminate, $this->log(AuditFailure::Refuse)->record($this->ability(), $offline)->verdict, 'Permit-offline is always recorded.');
    }

    public function testADenyWhoseRecordCantBeWrittenStaysADenyAndRaisesTheAlert(): void
    {
        $log = $this->log(AuditFailure::Refuse);
        $this->store->failing = true;
        $deny = $this->decided(Decision::deny('No.'));
        $unknown = $this->decided(Decision::indeterminate([IndeterminateReason::MissingData], 'Can\'t tell.'));

        self::assertEquals($deny, $log->record($this->ability(), $deny));
        self::assertEquals($unknown, $log->record($this->ability(), $unknown));
        self::assertCount(2, $this->events->of(AuditFailed::class));
    }

    public function testAPermitThatIsntRecordedIsntRefused(): void
    {
        $this->store->failing = true;
        $permit = $this->decided(self::permit());

        self::assertEquals($permit, $this->log(AuditFailure::Refuse)->record($this->ability(), $permit), 'Sampling left it out: it\'s no audit record, so its failure can\'t refuse it.');
        self::assertSame([], $this->events->dispatched);
    }

    public function testAnOperationDoesntRunWhenItsRecordCantBeWritten(): void
    {
        $entries = new InMemorySecurityLogStore();
        [$operations, $badges] = $this->operations(AuditFailure::Refuse, $entries);
        $this->store->failing = true;
        $ana = new PrincipalId(new UuidV7());

        try {
            $operations->run(new IssueBadge($this->realm, $ana), new Member($this->realm, $ana), new Origin(OriginKind::Web));
            self::fail('It ran.');
        } catch (NotPermitted $refused) {
            self::assertSame([IndeterminateReason::AuditUnavailable], $refused->decision->indeterminate);
        }

        self::assertSame([], $badges->permits, 'The handler didn\'t run.');
        $entry = iterator_to_array($entries->entries($this->realm, 1), false)[0] ?? null;
        self::assertSame([Outcome::Denied, ['audit_unavailable']], [$entry?->outcome, $entry?->details['indeterminate'] ?? null], 'The security log has the refusal.');
        self::assertCount(1, $this->events->of(AuditFailed::class));
    }

    public function testContinuingAnOperationStillDoesntRunWithoutItsRecordAndRaisesTheAlert(): void
    {
        [$operations, $badges] = $this->operations(AuditFailure::Continue);
        $this->store->failing = true;
        $ana = new PrincipalId(new UuidV7());

        try {
            $operations->run(new IssueBadge($this->realm, $ana), new Member($this->realm, $ana), new Origin(OriginKind::Web));
            self::fail('It ran.');
        } catch (\RuntimeException $e) {
            self::assertSame('The database went away.', $e->getMessage(), 'As before M2-12.');
        }

        self::assertSame([], $badges->permits);
        self::assertTrue($this->events->of(AuditFailed::class)[0]->refused);
    }

    public function testAFailingListenerDoesntChangeWhatTheFailureLedTo(): void
    {
        $this->events->listen(AuditFailed::class, static function (): void {
            throw new \LogicException('The pager is down too.');
        });
        $this->store->failing = true;

        $refused = $this->log(AuditFailure::Refuse, PermitRecording::All)->record($this->ability(), $this->decided(self::permit()));
        self::assertSame([IndeterminateReason::AuditUnavailable], $refused->indeterminate);

        try {
            $this->log(AuditFailure::Continue)->record($this->operation(), $this->decided(self::permit()));
            self::fail('It went on.');
        } catch (\RuntimeException $e) {
            self::assertSame('The database went away.', $e->getMessage(), 'The original exception stands.');
        }

        $listener = $this->logger->of('error');
        self::assertCount(2, $listener);
        self::assertSame(['A listener of YorvoID\'s audit alert failed: {message}', 'The pager is down too.'], [$listener[0][1], $listener[0][2]['message']]);
    }

    public function testABatchIsWrittenInOneWrite(): void
    {
        $evaluator = Evaluator::builtIn(new ValidAuthority(), clock: $this->clock, decisions: $this->log(AuditFailure::Refuse, PermitRecording::All));
        $member = new Member($this->realm, new PrincipalId(new UuidV7()));

        $decisions = $evaluator->decideAbilities($member, 'acme.pages.edit', $this->realm, [[new ResourceRef('page', '1'), null], [new ResourceRef('page', '2'), null], [new ResourceRef('page', '3'), null]], new Origin(OriginKind::Web));

        self::assertSame(1, $this->store->writes);
        self::assertCount(3, array_filter($decisions, fn (Decision $decision): bool => null !== $this->store->find($this->realm, (string) $decision->id)));

        $this->store->failing = true;
        $decisions = $evaluator->decideAbilities($member, 'acme.pages.edit', $this->realm, [[new ResourceRef('page', '1'), null], [new ResourceRef('page', '2'), null]], new Origin(OriginKind::Web));
        self::assertSame([null, null], array_map(static fn (Decision $decision): ?string => $decision->hash, $decisions));
        self::assertSame(2, $this->events->of(AuditFailed::class)[0]->records, 'One alert for the write.');
    }

    public function testNoDecisionIsMorePermissiveForAFailedWrite(): void
    {
        $verdicts = [
            fn (): Decision => $this->decided(self::permit()),
            fn (): Decision => new Decision(Verdict::PermitOffline, AuthorizedBy::Ability, 'offline', [], [], [], (string) new UuidV7(), $this->clock->now()),
            fn (): Decision => $this->decided(Decision::deny('No.')),
            fn (): Decision => $this->decided(Decision::indeterminate([IndeterminateReason::StoreUnreachable], 'Down.')),
        ];
        // How permissive a verdict is: Permit, then Permit-offline, then the refusals.
        $rank = static fn (Decision $decision): int => match ($decision->verdict) {
            Verdict::Permit => 2,
            Verdict::PermitOffline => 1,
            Verdict::Deny, Verdict::Indeterminate => 0,
        };
        $random = new Randomizer(new Mt19937(0xA0D06));

        for ($i = 0; $i < 500; ++$i) {
            $mode = 0 === $random->getInt(0, 1) ? AuditFailure::Refuse : AuditFailure::Continue;
            $permits = [PermitRecording::None, PermitRecording::All][$random->getInt(0, 1)];
            $request = 0 === $random->getInt(0, 2) ? $this->operation() : $this->ability();
            $decision = $verdicts[$random->getInt(0, 3)]();
            $this->store->failing = 0 === $random->getInt(0, 1);

            try {
                $given = $this->log($mode, $permits)->record($request, $decision);
            } catch (\RuntimeException) {
                self::assertTrue(AuditFailure::Continue === $mode && null !== $request->operation && $this->store->failing, 'Only an operation\'s failed record throws, and only when continuing.');

                continue;
            }

            self::assertLessThanOrEqual($rank($decision), $rank($given), 'Never more permissive.');

            if (AuditFailure::Refuse === $mode && $this->store->failing && (null !== $request->operation || PermitRecording::All === $permits || $rank($decision) < 2)) {
                self::assertSame(0, $rank($given), 'Refusing, nothing permits without its record.');
                self::assertNull($given->hash);
            }
        }
    }

    public function testASecurityLogEntryThatCantBeWrittenRaisesTheAlertAndFails(): void
    {
        $failing = new class implements SecurityLogStore {
            public function append(Realm $realm, \Closure $build): LogEntry
            {
                throw new \RuntimeException('The disk is full.');
            }

            public function lock(Realm $realm): void
            {
            }

            public function entries(Realm $realm, int $fromSequence): iterable
            {
                return [];
            }

            public function head(Realm $realm): ?array
            {
                return null;
            }
        };
        $log = new SecurityLog($failing, new InMemoryTransactions(), $this->events, clock: $this->clock, logger: $this->logger);

        try {
            $log->record($this->realm, 'acme.badge.issue', Outcome::Success, new Origin(OriginKind::Console));
            self::fail('It was recorded.');
        } catch (\RuntimeException $e) {
            self::assertSame('The disk is full.', $e->getMessage());
        }

        $failed = $this->events->of(AuditFailed::class);
        self::assertSame([AuditFailed::SECURITY_LOG, 'acme', 1, true], [$failed[0]->log, $failed[0]->realm?->name, $failed[0]->records, $failed[0]->refused]);
    }

    public function testAnAlertWithoutARealmNamesNone(): void
    {
        new AuditAlarm($this->events, $this->logger)->raise(new AuditFailed(AuditFailed::VERIFIER_LOG, null, 1, true, new \RuntimeException('Gone.')));

        self::assertNull($this->logger->of('critical')[0][2]['realm']);
        self::assertSame(['YorvoID couldn\'t write {records} audit record(s) to the {log}{refused}: {message}', 'verifier log'], [$this->logger->of('critical')[0][1], $this->logger->of('critical')[0][2]['log']]);
    }

    private function log(AuditFailure $mode, PermitRecording $permits = PermitRecording::None): DecisionLog
    {
        return new DecisionLog($this->store, $permits, logger: $this->logger, clock: $this->clock, onFailure: $mode, events: $this->events);
    }

    /**
     * @return array{Operations, LoggingBadges}
     */
    private function operations(AuditFailure $mode, InMemorySecurityLogStore $entries = new InMemorySecurityLogStore()): array
    {
        $log = new SecurityLog($entries, new InMemoryTransactions(), clock: $this->clock);
        $badges = new LoggingBadges($log);
        $evaluator = Evaluator::builtIn(new ValidAuthority(), selfService: new SelfService(), clock: $this->clock, decisions: $this->log($mode));

        return [new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), $evaluator, $log, [$badges]), $badges];
    }

    private function ability(): Request
    {
        return Request::forAbility('acme.pages.edit', $this->realm, new ResourceRef('page', '42'), new Member($this->realm, new PrincipalId(new UuidV7())), new Origin(OriginKind::Web), $this->clock->now());
    }

    private function operation(): Request
    {
        $ana = new PrincipalId(new UuidV7());

        return Request::forOperation(new OperationCatalogue([IssueBadge::class])->forCommand(IssueBadge::class), new IssueBadge($this->realm, $ana), new Member($this->realm, $ana), new Origin(OriginKind::Web), $this->clock->now());
    }

    private function decided(Decision $decision): Decision
    {
        return $decision->identified((string) new UuidV7(), $this->clock->now(), ['policy' => 1]);
    }

    private static function permit(): Decision
    {
        return Decision::permit(AuthorizedBy::Ability, 'A permit applies.');
    }
}
