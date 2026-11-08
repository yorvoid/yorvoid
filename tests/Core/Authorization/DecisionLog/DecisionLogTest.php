<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\DecisionLog;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Authorization\DecisionLog\PermitRecording;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\VersionSource;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\RecordBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ScanBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\SelfService;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ValidAuthority;

/**
 * The decision log (AUZ-040, ADR-09): every decision identified with the versions it read, an
 * operation's recorded before it runs and cited by what it records, abilities' kept for the request.
 */
final class DecisionLogTest extends TestCase
{
    private Realm $realm;

    private MockClock $clock;

    private FailingDecisionLogStore $store;

    private InMemorySecurityLogStore $entries;

    private SecurityLog $log;

    protected function setUp(): void
    {
        $this->realm = new Realm('acme');
        $this->clock = new MockClock('2026-11-08 18:00:00 UTC');
        $this->store = new FailingDecisionLogStore();
        $this->entries = new InMemorySecurityLogStore();
        $this->log = new SecurityLog($this->entries, new InMemoryTransactions(), clock: $this->clock);
    }

    public function testEachDecisionHasAnIdItsTimeAndTheVersionsItRead(): void
    {
        $failing = new class implements VersionSource {
            public function name(): string
            {
                return 'broken';
            }

            public function version(Realm $realm): int
            {
                throw new \RuntimeException('Unreachable.');
            }
        };
        $evaluator = Evaluator::builtIn(new ValidAuthority(), clock: $this->clock, versions: [self::version('relations', 4), $failing, self::version('policy', 12)]);

        $first = $evaluator->decideAbility(new Operator(), 'acme.pages.edit', $this->realm, null, new Origin(OriginKind::Console));
        $second = $evaluator->decideAbility(new Operator(), 'acme.pages.edit', $this->realm, null, new Origin(OriginKind::Console));

        self::assertTrue(UuidV7::isValid((string) $first->id));
        self::assertNotSame($first->id, $second->id);
        self::assertEquals($this->clock->now(), $first->at);
        self::assertSame(['relations' => 4, 'policy' => 12], $first->versions, 'A version that can\'t be read is left out.');
        self::assertNull($first->hash, 'Without a decision log, nothing is recorded.');
    }

    public function testAnOperationsDecisionIsRecordedBeforeItRuns(): void
    {
        [$operations, $badges] = $this->operations();
        $ana = new PrincipalId(new UuidV7());
        $badges->onRun = function (Permit $permit): void {
            self::assertNotNull($this->store->find($this->realm, $permit->decisionId), 'It\'s recorded before the operation runs.');
        };

        $operations->run(new IssueBadge($this->realm, $ana), new Member($this->realm, $ana), new Origin(OriginKind::Web, correlationId: 'request-1'));

        $permit = $badges->permits[0];
        $record = $this->store->find($this->realm, $permit->decisionId);
        self::assertNotNull($record);
        self::assertSame(['operation', 'acme.badge.issue', (string) $ana, 'permit', 'self', ['web', 'request-1'], ['policy' => 3]], [$record->kind, $record->name, $record->actor, $record->verdict, $record->basis, $record->origin, $record->versions]);
        self::assertSame(1, $this->store->writes, 'One write; the consequence is no decision of its own.');
    }

    public function testWhatAnOperationRecordsCitesItsDecision(): void
    {
        [$operations, $badges] = $this->operations();
        $ana = new PrincipalId(new UuidV7());

        $operations->run(new IssueBadge($this->realm, $ana), new Member($this->realm, $ana), new Origin(OriginKind::Web));

        $id = $badges->permits[0]->decisionId;
        $hash = $this->store->find($this->realm, $id)?->hash();
        self::assertNotNull($hash);
        self::assertSame([
            ['acme.badge.issue', ['decision' => ['hash' => $hash, 'id' => $id]]],
            ['acme.badge.record', ['badge' => 'recorded', 'decision' => ['hash' => $hash, 'id' => $id]]],
        ], array_map(static fn (LogEntry $entry): array => [$entry->action, $entry->details], $this->entries()), 'Its consequences cite it too.');

        $this->log->record($this->realm, 'acme.badge.inspect', Outcome::Success, new Origin(OriginKind::Console));
        self::assertSame([], $this->entries()[2]->details, 'Once it ran, nothing cites it.');
    }

    public function testARefusalIsRecordedAndItsLogEntryCitesIt(): void
    {
        [$operations] = $this->operations();
        $ana = new PrincipalId(new UuidV7());

        try {
            $operations->run(new IssueBadge($this->realm, new PrincipalId(new UuidV7())), new Member($this->realm, $ana), new Origin(OriginKind::Web));
            self::fail('It was permitted.');
        } catch (NotPermitted $refused) {
            $decision = $refused->decision;
        }

        $record = $this->store->find($this->realm, (string) $decision->id);
        self::assertSame('deny', $record?->verdict);
        self::assertSame($record->hash(), $decision->hash);
        self::assertSame(['hash' => $decision->hash, 'id' => $decision->id], $this->entries()[0]->details['decision'] ?? null);
        self::assertSame(Outcome::Denied, $this->entries()[0]->outcome);
    }

    public function testAnOperationThatFailsStopsCitingItsDecision(): void
    {
        [$operations] = $this->operations();

        try {
            $operations->run(new ScanBadge($this->realm, 'K1'), new Operator(), new Origin(OriginKind::Console));
            self::fail('The scanner didn\'t jam.');
        } catch (\RuntimeException $e) {
            self::assertSame('The scanner jammed.', $e->getMessage());
        }

        self::assertArrayHasKey('decision', $this->entries()[0]->details);
        $this->log->record($this->realm, 'acme.badge.inspect', Outcome::Success, new Origin(OriginKind::Console));
        self::assertArrayNotHasKey('decision', $this->entries()[1]->details);
    }

    public function testAnEntryKeepsADecisionItNamesItself(): void
    {
        $decision = Decision::permit(\YorvoID\Core\Operation\AuthorizedBy::Operator, 'Ran.')->identified('d-1', $this->clock->now(), []);

        $this->log->citing($decision, fn (): LogEntry => $this->log->record($this->realm, 'acme.badge.inspect', Outcome::Success, new Origin(OriginKind::Console), details: ['decision' => 'its own']));
        $this->log->citing($decision, function (): void {
            $this->log->reset();
            $this->log->record($this->realm, 'acme.badge.inspect', Outcome::Success, new Origin(OriginKind::Console));
        });
        $this->log->citing(Decision::permit(\YorvoID\Core\Operation\AuthorizedBy::Operator, 'Ran.'), fn (): LogEntry => $this->log->record($this->realm, 'acme.badge.inspect', Outcome::Success, new Origin(OriginKind::Console)));

        self::assertSame([['decision' => 'its own'], [], []], array_map(static fn (LogEntry $entry): array => $entry->details, $this->entries()), 'A reset, or a decision without an ID, cites nothing.');
        self::assertSame(['decision' => ['id' => 'd-1']], $this->log->citing($decision, fn (): array => $this->log->record($this->realm, 'acme.badge.inspect', Outcome::Success, new Origin(OriginKind::Console))->details), 'Without a decision log, only its ID.');
    }

    public function testAnOperationDoesntRunWhenItsDecisionCantBeRecorded(): void
    {
        [$operations, $badges] = $this->operations();
        $this->store->failing = true;
        $ana = new PrincipalId(new UuidV7());

        try {
            $operations->run(new IssueBadge($this->realm, $ana), new Member($this->realm, $ana), new Origin(OriginKind::Web));
            self::fail('It ran.');
        } catch (\RuntimeException $e) {
            self::assertSame('The database went away.', $e->getMessage());
        }

        self::assertSame([], $badges->permits);
        self::assertSame([], $this->entries());
    }

    public function testAnAbilitysRefusalWaitsForTheEndOfTheRequest(): void
    {
        $log = new DecisionLog($this->store);
        $denied = $log->record($request = $this->ability(), $this->decided(Decision::deny('No permit applies.')));
        $unknown = $log->record($this->ability(), $this->decided(Decision::indeterminate([IndeterminateReason::MissingData], 'Can\'t tell.')));

        self::assertNotNull($denied->hash);
        self::assertNotNull($unknown->hash);
        self::assertNull($this->store->records->find($this->realm, (string) $denied->id), 'Nothing is written before the end.');

        $log->flush();
        $log->flush();

        self::assertSame($denied->hash, $this->store->find($this->realm, (string) $denied->id)?->hash());
        self::assertSame($unknown->hash, $this->store->find($this->realm, (string) $unknown->id)?->hash());
        self::assertSame(1, $this->store->writes, 'Written together, once.');
        self::assertSame(DecisionRecord::of($request, $denied)->hash(), $denied->hash, 'The hash is the record\'s.');

        $again = $log->record($this->ability(), $this->decided(Decision::deny('No permit applies.')));
        $log->reset();
        self::assertNotNull($this->store->find($this->realm, (string) $again->id), 'A reset writes what\'s waiting.');
    }

    public function testAHundredWaitingAreWrittenAtOnce(): void
    {
        $log = new DecisionLog($this->store);

        for ($i = 1; $i < DecisionLog::BUFFER; ++$i) {
            $log->record($this->ability(), $this->decided(Decision::deny('No.')));
        }

        self::assertSame(0, $this->store->writes);
        $last = $log->record($this->ability(), $this->decided(Decision::deny('No.')));
        self::assertSame(1, $this->store->writes);
        self::assertNotNull($this->store->find($this->realm, (string) $last->id));
    }

    public function testAFailedWriteOfWaitingDecisionsIsReportedNotThrown(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string, array<mixed>}> */
            public array $logs = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = [$level, (string) $message, $context];
            }
        };
        $log = new DecisionLog($this->store, logger: $logger);
        $log->record($this->ability(), $this->decided(Decision::deny('No.')));
        $this->store->failing = true;

        $log->flush();
        $this->store->failing = false;
        $log->flush();

        self::assertSame([['error', 'YorvoID couldn\'t record {count} decisions: {message}', 1, 'The database went away.']], array_map(static fn (array $entry): array => [$entry[0], $entry[1], $entry[2]['count'], $entry[2]['message']], $logger->logs));
        self::assertSame(0, $this->store->writes, 'What failed isn\'t written again.');
    }

    public function testWhichPermitsOfAbilitiesAreRecorded(): void
    {
        $recorded = function (DecisionLog $log, string $ability = 'acme.pages.edit', string $correlation = 'request-1'): bool {
            $decision = $log->record($this->ability($ability, $correlation), $this->decided(Decision::permit(\YorvoID\Core\Operation\AuthorizedBy::Ability, 'Holds it.')));
            $log->flush();

            return null !== $decision->hash && null !== $this->store->find($this->realm, (string) $decision->id);
        };

        self::assertFalse($recorded(new DecisionLog($this->store)), 'None, by default.');
        self::assertTrue($recorded(new DecisionLog($this->store, PermitRecording::All)));
        self::assertTrue($recorded(new DecisionLog($this->store, alwaysRecord: ['acme.access.explain']), 'acme.access.explain'), 'An ability that always records its permits.');
        self::assertFalse($recorded(new DecisionLog($this->store, alwaysRecord: ['acme.access.explain'])));
        self::assertFalse($recorded(new DecisionLog($this->store, PermitRecording::Sampled, 0.0)));
        self::assertTrue($recorded(new DecisionLog($this->store, PermitRecording::Sampled, 1.0)));

        $sampled = new DecisionLog($this->store, PermitRecording::Sampled, 0.5);
        $requests = [];

        for ($i = 0; $i < 200; ++$i) {
            $first = $recorded($sampled, correlation: 'request-'.$i);
            self::assertSame($first, $recorded($sampled, 'acme.pages.view', 'request-'.$i), 'A request is recorded whole or not at all.');
            $requests[] = $first;
        }

        self::assertGreaterThan(60, \count(array_filter($requests)));
        self::assertLessThan(140, \count(array_filter($requests)), 'About half.');
    }

    public function testTheSampleRateIsAShare(): void
    {
        foreach ([-0.1, 1.1] as $rate) {
            try {
                new DecisionLog($this->store, PermitRecording::Sampled, $rate);
                self::fail(\sprintf('%s was accepted.', $rate));
            } catch (IntegratorError $e) {
                self::assertStringStartsWith(\sprintf('The decision log\'s sample rate is %s.', $rate), $e->getMessage());
            }
        }
    }

    public function testOldDecisionsArePurged(): void
    {
        $log = new DecisionLog($this->store, clock: $this->clock);
        $old = $log->record($this->ability(), Decision::deny('No.')->identified('old', $this->clock->now()->modify('-31 days'), []));
        $recent = $log->record($this->ability(), Decision::deny('No.')->identified('recent', $this->clock->now()->modify('-29 days'), []));
        $log->flush();

        self::assertSame(1, $log->purge(Duration::days(30)));
        self::assertNull($this->store->find($this->realm, (string) $old->id));
        self::assertNotNull($this->store->find($this->realm, (string) $recent->id));
    }

    public function testOnlyAnIdentifiedDecisionIsRecorded(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('Only a decision with an ID and a time can be recorded.');

        new DecisionLog($this->store)->record($this->ability(), Decision::deny('No.'));
    }

    /**
     * @return array{Operations, LoggingBadges}
     */
    private function operations(): array
    {
        $badges = new LoggingBadges($this->log);
        $evaluator = Evaluator::builtIn(new ValidAuthority(), selfService: new SelfService(), clock: $this->clock, versions: [self::version('policy', 3)], decisions: new DecisionLog($this->store));

        return [new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), $evaluator, $this->log, [$badges]), $badges];
    }

    private function ability(string $ability = 'acme.pages.edit', string $correlation = 'request-1'): Request
    {
        return Request::forAbility($ability, $this->realm, new ResourceRef('page', '42'), new Member($this->realm, new PrincipalId(new UuidV7())), new Origin(OriginKind::Web, correlationId: $correlation), $this->clock->now());
    }

    private function decided(Decision $decision): Decision
    {
        return $decision->identified((string) new UuidV7(), $this->clock->now(), ['policy' => 1]);
    }

    /**
     * @return list<LogEntry>
     */
    private function entries(): array
    {
        return iterator_to_array($this->entries->entries($this->realm, 1), false);
    }

    private static function version(string $name, int $version): VersionSource
    {
        return new readonly class($name, $version) implements VersionSource {
            public function __construct(
                private string $name,
                private int $version,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function version(Realm $realm): int
            {
                return $this->version;
            }
        };
    }
}
