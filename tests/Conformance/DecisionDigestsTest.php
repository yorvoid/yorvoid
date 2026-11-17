<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\DecisionLog\DecisionDigests;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
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
