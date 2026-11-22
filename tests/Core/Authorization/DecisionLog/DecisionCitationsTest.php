<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\DecisionLog;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\DecisionLog\DecisionDigests;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Authorization\DecisionLog\Storage\InMemoryDecisionLogStore;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\RecordBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ScanBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\SelfService;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ValidAuthority;
use YorvoID\Tests\Support\Decisions;
use YorvoID\Tests\Support\TrustedActors;

/**
 * Every entry of the chain that cites a decision cites a permit the decision log holds (D-50): one
 * permitted by a decision point that records nothing, e.g. a second Operations, is found.
 */
final class DecisionCitationsTest extends TestCase
{
    private InMemorySecurityLogStore $entries;

    public function testAnEntryCitingADecisionTheLogDoesntHoldIsFound(): void
    {
        $realm = new Realm('acme');
        $entries = new InMemorySecurityLogStore();
        $transactions = new InMemoryTransactions();
        $log = new SecurityLog($entries, $transactions);
        $decisions = new InMemoryDecisionLogStore();
        $digests = new DecisionDigests($decisions, $log, $entries, $transactions);
        $ana = new PrincipalId(new UuidV7());

        $this->operations($log, new DecisionLog($decisions))->run(new IssueBadge($realm, $ana), new Member($realm, $ana), new Origin(OriginKind::Web));
        self::assertSame([], $digests->citations($realm), 'The application\'s decision point records what its entries cite.');

        // A second one, over an evaluator that records nothing.
        $this->operations($log, null)->run(new IssueBadge($realm, $ana), new Member($realm, $ana), new Origin(OriginKind::Web));
        $problems = $digests->citations($realm);

        self::assertCount(2, $problems, 'Its operation\'s entry, and its consequence\'s.');
        self::assertStringContainsString('(acme.badge.issue) cites the decision', $problems[0]);
        self::assertStringContainsString('which the decision log doesn\'t have.', $problems[0]);
    }

    public function testACitationWithAnotherHashThanItsRecordsIsFound(): void
    {
        [$realm, $log, $digests, $decisions] = $this->setUpLog();
        $ana = new PrincipalId(new UuidV7());
        $this->operations($log, new DecisionLog($decisions))->run(new IssueBadge($realm, $ana), new Member($realm, $ana), new Origin(OriginKind::Web));
        $id = $this->citedIds($this->entries, $realm)[0];
        $permit = Decisions::identified(Decisions::permit(AuthorizedBy::Self, 'Cited.'), $id, new \DateTimeImmutable());

        // The ID alone, of a recorded permit, is a citation that holds.
        $log->citing($permit, static function () use ($log, $realm): void {
            $log->record($realm, 'acme.badge.note', Outcome::Success, new Origin(OriginKind::Web));
        });
        self::assertSame([], $digests->citations($realm));

        $log->citing(Decisions::recorded($permit, '0000'), static function () use ($log, $realm): void {
            $log->record($realm, 'acme.badge.note', Outcome::Success, new Origin(OriginKind::Web));
        });
        self::assertSame([\sprintf('Entry 4 (acme.badge.note) cites the decision %s with another hash than its record\'s.', $id)], $digests->citations($realm));
    }

    public function testACitationOfADecisionThatRefusedIsFound(): void
    {
        [$realm, $log, $digests, $decisions] = $this->setUpLog();
        $refusal = Decision::deny('No.')->identified((string) new UuidV7(), new \DateTimeImmutable(), []);
        $decisions->add([DecisionRecord::of(Request::forAbility('acme.pages.edit', $realm, null, new Member($realm, new PrincipalId(new UuidV7())), new Origin(OriginKind::Web), new \DateTimeImmutable()), $refusal)]);

        $log->citing($refusal, static function () use ($log, $realm): void {
            $log->record($realm, 'acme.badge.note', Outcome::Success, new Origin(OriginKind::Web));
        });

        self::assertSame([\sprintf('Entry 1 (acme.badge.note) cites the decision %s, which is a deny.', $refusal->id)], $digests->citations($realm));
    }

    /**
     * An entry written once its records were pruned is still checked: only one from before is passed over.
     */
    public function testOnlyAnEntryFromBeforeThePruneIsPassedOver(): void
    {
        // The chain keeps an entry's time to the second.
        $clock = new MockClock('2026-11-19 18:00:00');
        [$realm, $log, $digests, $decisions] = $this->setUpLog($clock);
        $ana = new PrincipalId(new UuidV7());
        $this->operations($log, null)->run(new IssueBadge($realm, $ana), new Member($realm, $ana), new Origin(OriginKind::Web));

        $decisions->prune($clock->now());
        self::assertCount(2, $digests->citations($realm), 'Written at the moment the prune starts from: not pruned.');

        $decisions->prune($clock->now()->modify('+1 microsecond'));
        self::assertSame([], $digests->citations($realm));
    }

    /**
     * @return array{Realm, SecurityLog, DecisionDigests, InMemoryDecisionLogStore}
     */
    private function setUpLog(?MockClock $clock = null): array
    {
        $entries = $this->entries = new InMemorySecurityLogStore();
        $transactions = new InMemoryTransactions();
        $log = new SecurityLog($entries, $transactions, clock: $clock ?? new MockClock());
        $decisions = new InMemoryDecisionLogStore();

        return [new Realm('acme'), $log, new DecisionDigests($decisions, $log, $entries, $transactions), $decisions];
    }

    /**
     * @return list<string>
     */
    private function citedIds(InMemorySecurityLogStore $entries, Realm $realm): array
    {
        $ids = [];

        foreach ($entries->entries($realm, 1) as $entry) {
            $cited = $entry->details['decision'] ?? null;

            if (\is_array($cited) && \is_string($cited['id'] ?? null)) {
                $ids[] = $cited['id'];
            }
        }

        return $ids;
    }

    private function operations(SecurityLog $log, ?DecisionLog $decisions): Operations
    {
        $evaluator = Evaluator::builtIn(new ValidAuthority(), selfService: new SelfService(), decisions: $decisions, actors: [new TrustedActors()]);

        return new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), $evaluator, $log, [new LoggingBadges($log)]);
    }
}
