<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\Event\DecisionRecorded;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Badges;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Policy;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\RecordBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ScanBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\SelfService;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ValidAuthority;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\RecordingDispatcher;
use YorvoID\Tests\Support\Stack;

/**
 * A refusal or a failed attempt changes nothing, so it's no entry of the chain: it's a record of the
 * decision log, written outside the caller's transaction, without the realm's lock (D-42, AR-03).
 */
final class RefusalsOutsideTheChainTest extends DatabaseTestCase
{
    #[DataProvider('implementations')]
    public function testARefusalSurvivesTheCallersRollback(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $operations = $this->operations($stack, $stack);
        $realm = new Realm('acme');
        $refused = null;

        try {
            // The application's own transaction, rolled back with the refusal, as a framework's wrapper does.
            $stack->transactions->run(static function () use ($operations, $realm, $stack): void {
                $stack->log->append($realm, static fn (int $sequence, ?string $previous) => \YorvoID\Core\Log\LogEntry::create($realm, $sequence, new UuidV7(), new \DateTimeImmutable(), 'acme.other.change', Outcome::Success, new Origin(OriginKind::Web), null, null, null, [], $previous ?? ''));
                $operations->run(new IssueBadge($realm, new PrincipalId(new UuidV7())), new Member($realm, new PrincipalId(new UuidV7())), new Origin(OriginKind::Web));
            });
        } catch (NotPermitted $e) {
            $refused = $e->decision;
        }

        self::assertNotNull($refused);
        self::assertSame('deny', $stack->decisions->find($realm, (string) $refused->id)?->verdict, 'The refusal was rolled back with the caller.');
        self::assertSame([], iterator_to_array($stack->log->entries($realm, 1), false), 'The chain has the refusal, or the caller\'s rolled-back change.');
    }

    public function testARefusalTakesNoRealmLock(): void
    {
        $holder = $this->stack('postgresql');
        $other = $this->stack('postgresql');
        $database = $other->database;
        \assert($database instanceof Database);
        // Waiting would fail rather than hang the suite.
        $database->connection->executeStatement("SET lock_timeout = '3s'");
        $operations = $this->operations($other, $other);
        $realm = new Realm('acme');
        $started = microtime(true);

        $holder->transactions->run(static function () use ($holder, $realm, $operations): void {
            // Another request holds the realm's log lock until it commits.
            new SecurityLog($holder->log, $holder->transactions)->lock($realm);

            try {
                $operations->run(new IssueBadge($realm, new PrincipalId(new UuidV7())), new Member($realm, new PrincipalId(new UuidV7())), new Origin(OriginKind::Web));
                self::fail('It was permitted.');
            } catch (NotPermitted) {
            }
        });

        self::assertLessThan(2.0, microtime(true) - $started, 'The refusal waited for the realm\'s lock.');
    }

    #[DataProvider('implementations')]
    public function testAFailedAttemptIsARecordNotAnEntry(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $events = new RecordingDispatcher();
        $decisions = new DecisionLog($stack->decisions, events: $events, transactions: $stack->transactions);
        $log = new SecurityLog($stack->log, $stack->transactions, attempts: $decisions);
        $realm = new Realm('acme');

        $stack->transactions->run(static function () use ($log, $realm): void {
            $log->attempt($realm, 'yorvoid.recovery_code.use', Outcome::Failed, new Origin(OriginKind::Web, correlationId: 'r-1'), null, 'credential', 'c-1', ['principal' => 'p-1']);
        });

        self::assertSame([], iterator_to_array($stack->log->entries($realm, 1), false));
        $records = $this->attempts($stack, $realm);
        self::assertCount(1, $records);
        self::assertSame(['attempt', 'yorvoid.recovery_code.use', 'failed', ['principal' => 'p-1']], [$records[0]->kind, $records[0]->name, $records[0]->verdict, $records[0]->details]);
        $recorded = $events->of(DecisionRecorded::class);
        self::assertCount(1, $recorded, 'A SIEM sees no attempts.');
        self::assertSame($records[0]->id, $recorded[0]->record->id);
    }

    /**
     * @return list<\YorvoID\Core\Authorization\DecisionLog\DecisionRecord>
     */
    private function attempts(Stack $stack, Realm $realm): array
    {
        $database = $stack->database;

        if (null === $database) {
            $memory = $stack->decisions;
            \assert($memory instanceof \YorvoID\Core\Authorization\DecisionLog\Storage\InMemoryDecisionLogStore);

            /** @var array<string, array<string, \YorvoID\Core\Authorization\DecisionLog\DecisionRecord>> $byRealm */
            $byRealm = new \ReflectionProperty($memory, 'records')->getValue($memory);

            return array_values(array_filter($byRealm[$realm->name] ?? [], static fn ($record): bool => 'attempt' === $record->kind));
        }

        $ids = $database->connection->fetchFirstColumn(\sprintf('SELECT id FROM %s WHERE realm = ?', $database->table(DbalDecisionLogStore::TABLE)), [$realm->name]);

        return array_values(array_filter(array_map(static fn (mixed $id) => $stack->decisions->find($realm, \is_string($id) ? $id : ''), $ids), static fn ($record): bool => 'attempt' === $record?->kind));
    }

    private function operations(Stack $stack, Stack $decisionsOn): Operations
    {
        $decisions = new DecisionLog($decisionsOn->decisions, transactions: $stack->transactions);

        return new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), Evaluator::builtIn(new ValidAuthority(), new Policy(false), new SelfService(), decisions: $decisions), new SecurityLog($stack->log, $stack->transactions, attempts: $decisions), [new Badges()]);
    }
}
