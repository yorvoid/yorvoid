<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\DecisionLog\DecisionDigests;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Duration;
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
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Badges;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Policy;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\RecordBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ScanBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\SelfService;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\ValidAuthority;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Stack;

/**
 * Each checkpoint's digest covers the decision records written apart from a caller's transaction since
 * the last (D-42): a record changed or removed afterwards no longer matches, a pruned window is kept by
 * its digest, and a record written inside a caller's transaction is left to its citation (I2).
 */
final class DecisionDigestsTest extends DatabaseTestCase
{
    private const int GRACE = 1;

    #[DataProvider('implementations')]
    public function testDigestsCoverWhatWasWrittenAndFindWhatChanged(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $digests = $this->digests($stack);
        $realm = new Realm('acme');

        self::assertNull($digests->take($realm), 'Nothing to digest appended an entry.');
        [$changed, $kept, $removed] = [self::record(), self::record(), self::record()];
        $stack->decisions->add([$changed, $kept]);
        self::wait();
        $first = $digests->take($realm);
        $stack->decisions->add([$removed]);
        self::wait();
        $second = $digests->take($realm);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame([2, 1], [$first->details['count'] ?? null, $second->details['count'] ?? null]);
        self::assertSame(DecisionDigests::ACTION, $first->action);
        $verified = $digests->verify($realm);
        self::assertTrue($verified->isIntact(), implode(' ', $verified->problems));
        self::assertSame([2, 0, 0], [$verified->windows, $verified->pruned, $verified->undigested]);

        $stack->decisions->add([self::record()]);
        self::assertSame(1, $digests->verify($realm)->undigested, 'A record since the last digest isn\'t counted as undigested.');

        $database = $stack->database;

        if (null === $database) {
            return;
        }

        // A database writer changes one refusal, and removes another.
        $table = $database->table(DbalDecisionLogStore::TABLE);
        $database->connection->executeStatement(\sprintf("UPDATE %s SET content = REPLACE(content, '\"deny\"', '\"permit\"') WHERE id = ?", $table), [$changed->id]);
        $database->connection->executeStatement(\sprintf('DELETE FROM %s WHERE id = ?', $table), [$removed->id]);
        $verified = $digests->verify($realm);

        self::assertFalse($verified->isIntact());
        self::assertCount(2, $verified->problems, implode(' ', $verified->problems));

        // Pruned since: kept by the digests only.
        $stack->decisions->prune(new \DateTimeImmutable('+1 day'));
        $verified = $digests->verify($realm);
        self::assertSame([true, 2], [$verified->isIntact(), $verified->pruned]);
    }

    /**
     * A permit's record inserted early in a long transaction of the application's, which commits after
     * a digest of its time: left out of the digest, so verifying doesn't take it for a forgery (I2).
     * PostgreSQL: on SQLite, another process can't write while the transaction is open.
     */
    public function testARecordWrittenInsideACallersTransactionIsLeftToItsCitation(): void
    {
        $platform = 'postgresql';
        $stack = $this->stack($platform);
        $digests = $this->digests($stack);
        $realm = new Realm('acme');
        $database = $stack->database;
        \assert($database instanceof Database);

        $database->connection->beginTransaction();
        $stack->decisions->add([self::record()]);
        self::wait();
        // From another process, while the application's transaction is still open.
        $other = $this->stack($platform);
        $this->digests($other)->take($realm);
        $database->connection->commit();
        self::wait();
        $digests->take($realm);

        $verified = $digests->verify($realm);
        self::assertTrue($verified->isIntact(), implode(' ', $verified->problems));
        self::assertSame(0, $verified->undigested);
    }

    /**
     * A failed attempt and a refusal in a transaction of the application's that commits: written once
     * it has ended, apart, so the next digest covers them, and a deletion is reported (C1 of G-4).
     */
    #[DataProvider('platforms')]
    public function testWhatIsWrittenAfterTheApplicationsCommitIsDigested(string $platform): void
    {
        $stack = $this->stack($platform);
        $database = $stack->database;
        \assert($database instanceof Database);
        $digests = $this->digests($stack);
        $realm = new Realm('acme');
        $decisions = new DecisionLog($stack->decisions, transactions: $stack->transactions);
        $log = new SecurityLog($stack->log, $stack->transactions, attempts: $decisions);
        $operations = new Operations(new OperationCatalogue([IssueBadge::class, ScanBadge::class, RecordBadge::class]), Evaluator::builtIn(new ValidAuthority(), new Policy(false), new SelfService(), decisions: $decisions), $log, [new Badges()]);

        $database->connection->beginTransaction();
        $log->attempt($realm, 'yorvoid.setup.claim', Outcome::Failed, new Origin(OriginKind::Web));

        try {
            $operations->run(new IssueBadge($realm, new PrincipalId(new UuidV7())), new Member($realm, new PrincipalId(new UuidV7())), new Origin(OriginKind::Web));
        } catch (NotPermitted) {
        }

        $database->connection->commit();
        self::wait();
        $digest = $digests->take($realm);

        self::assertSame(2, $digest?->details['count'] ?? null, 'What was written after the application\'s commit isn\'t digested.');
        self::assertTrue($digests->verify($realm)->isIntact());
        $database->connection->executeStatement(\sprintf('DELETE FROM %s WHERE realm = ?', $database->table(DbalDecisionLogStore::TABLE)), [$realm->name]);
        self::assertFalse($digests->verify($realm)->isIntact(), 'Deleting the attempt and the refusal went unnoticed.');
    }

    /**
     * Without YorvoID's middleware the end of the application's transaction can't be seen, so a refusal
     * is written at once, inside it: left to the store to tell, it's not digested, and a transaction that
     * stays open across a digest isn't taken for a forgery (C5 of G-4).
     */
    public function testARefusalWrittenInsideATransactionWithoutTheMiddlewareIsntTakenForAForgery(): void
    {
        $stack = $this->stack('postgresql');
        $database = $stack->database;
        \assert($database instanceof Database);
        $digests = $this->digests($stack);
        $realm = new Realm('acme');
        $plain = new DbalTransactions($database->connection);
        $log = new SecurityLog($stack->log, $plain, attempts: new DecisionLog($stack->decisions, transactions: $plain));

        // One written apart before: the digest taken while the transaction is open has an entry whose
        // window spans the refusal's time.
        $stack->decisions->add([self::record()], true);
        $database->connection->beginTransaction();
        $log->attempt($realm, 'yorvoid.setup.claim', Outcome::Failed, new Origin(OriginKind::Web));
        self::wait();
        self::assertSame(1, $this->digests($this->stack('postgresql'))->take($realm)?->details['count'] ?? null);
        $database->connection->commit();
        self::wait();
        $digests->take($realm);

        $verified = $digests->verify($realm);
        self::assertTrue($verified->isIntact(), implode(' ', $verified->problems));
    }

    /**
     * On a connection of its own (decision_log.connection), a refusal inside the application's open
     * transaction is written at once and apart: another process sees it before the commit, and the next
     * digest covers it (C1 of G-4).
     */
    public function testARefusalOnTheDecisionLogsOwnConnectionIsWrittenAtOnceAndDigested(): void
    {
        $stack = $this->stack('postgresql');
        $database = $stack->database;
        \assert($database instanceof Database);
        $realm = new Realm('acme');
        $decisions = new DecisionLog(new DbalDecisionLogStore($database, $this->database('postgresql')->connection), transactions: $stack->transactions);
        $log = new SecurityLog($stack->log, $stack->transactions, attempts: $decisions);
        $other = $this->database('postgresql');

        $database->connection->beginTransaction();
        $log->attempt($realm, 'yorvoid.setup.claim', Outcome::Failed, new Origin(OriginKind::Web));
        $written = $other->connection->fetchOne(\sprintf('SELECT written_us FROM %s WHERE realm = ?', $other->table(DbalDecisionLogStore::TABLE)), [$realm->name]);

        self::assertNotFalse($written, 'It waited for the application\'s transaction to end.');
        self::assertNotNull($written, 'Written apart, but not as apart: no digest covers it.');
        self::wait();
        self::assertSame(1, $this->digests($this->stack('postgresql'))->take($realm)?->details['count'] ?? null);
        $database->connection->commit();
    }

    private function digests(Stack $stack): DecisionDigests
    {
        return new DecisionDigests($stack->decisions, new SecurityLog($stack->log, $stack->transactions), $stack->log, $stack->transactions, Duration::seconds(self::GRACE));
    }

    private static function record(): DecisionRecord
    {
        return DecisionRecord::attempt((string) new UuidV7(), new Realm('acme'), new \DateTimeImmutable(), 'yorvoid.setup.claim', 'deny', null, null, null, new Origin(OriginKind::Web), []);
    }

    private static function wait(): void
    {
        usleep(self::GRACE * 1_000_000 + 100_000);
    }
}
