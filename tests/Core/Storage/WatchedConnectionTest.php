<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Storage;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use YorvoID\Core\Log\AuditAlarm;
use YorvoID\Core\Log\Event\AuditFailed;
use YorvoID\Core\Storage\Dbal\CommitHooks;
use YorvoID\Core\Storage\Dbal\LockOrder;
use YorvoID\Core\Storage\Dbal\WatchedConnection;
use YorvoID\Tests\Support\RecordingDispatcher;

/**
 * The driver connection YorvoID watches: every way a transaction ends ends it for the lock-order check
 * too, with the check on or off; savepoints are followed by name, as the databases read them (D-52).
 */
final class WatchedConnectionTest extends TestCase
{
    private const string VERSION_BUMP = 'UPDATE "yorvoid_policy_version" SET version = version + 1';

    private RecordingDispatcher $alerts;

    private CommitHooks $hooks;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $ran;

    protected function setUp(): void
    {
        $this->alerts = new RecordingDispatcher();
        $this->ran = new \ArrayObject();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function checks(): iterable
    {
        yield 'checked' => [true];
        yield 'not checked' => [false];
    }

    /**
     * @return iterable<string, array{\Closure(WatchedConnection, CommitHooks): void}>
     */
    public static function ends(): iterable
    {
        yield 'committed' => [static function (WatchedConnection $driver): void {
            $driver->beginTransaction();
            $driver->commit();
        }];
        yield 'rolled back' => [static function (WatchedConnection $driver): void {
            $driver->beginTransaction();
            $driver->rollBack();
        }];
        yield 'a callback\'s, committed by DBAL' => [static function (WatchedConnection $driver): void {
            $driver->beginApart();
            $driver->commit();
        }];
        yield 'a callback\'s, rolled back by DBAL' => [static function (WatchedConnection $driver): void {
            $driver->beginApart();
            $driver->rollBack();
        }];
        yield 'a callback\'s, committed' => [static function (WatchedConnection $driver): void {
            $driver->beginApart();
            $driver->commitApart();
        }];
        yield 'a callback\'s, rolled back' => [static function (WatchedConnection $driver): void {
            $driver->beginApart();
            $driver->rollBackApart();
        }];
        yield 'failed before the commit' => [static function (WatchedConnection $driver, CommitHooks $hooks): void {
            $driver->beginTransaction();
            $hooks->addBeforeCommit(static fn () => throw new \RuntimeException('The log is full.'));

            try {
                $driver->commit();
                self::fail('The failure before the commit wasn\'t thrown.');
            } catch (\RuntimeException $e) {
                self::assertSame('The log is full.', $e->getMessage());
            }
        }];
    }

    /**
     * @param \Closure(WatchedConnection, CommitHooks): void $end
     */
    #[DataProvider('ends')]
    public function testEveryEndEndsTheCheckedTransaction(\Closure $end): void
    {
        foreach (self::checks() as [$check]) {
            $driver = $this->driver($check);
            $end($driver, $this->hooks);

            // Outside a transaction, nothing is ordered: a version row may be written.
            $this->hooks->lockOrder?->saw(self::VERSION_BUMP);
            self::assertSame($check, null !== $this->hooks->lockOrder);
        }
    }

    /**
     * A callback's transaction is one the check follows, as the application's is.
     */
    #[DataProvider('checks')]
    public function testEveryBeginStartsACheckedTransaction(bool $check): void
    {
        foreach (['beginTransaction' => static fn (WatchedConnection $driver) => $driver->beginTransaction(), 'beginApart' => static fn (WatchedConnection $driver) => $driver->beginApart()] as $begin => $start) {
            $start($this->driver($check));

            try {
                $this->hooks->lockOrder?->saw(self::VERSION_BUMP);
                self::assertFalse($check, $begin.' started no transaction the check follows.');
            } catch (\LogicException $e) {
                self::assertStringStartsWith('A version row written without the realm\'s lock', $e->getMessage());
            }
        }
    }

    /**
     * What waits for the end runs after a failure before the commit, also when the driver's rollback
     * then fails.
     */
    #[DataProvider('checks')]
    public function testAFailureBeforeTheCommitRunsWhatWaitsForTheEnd(bool $check): void
    {
        foreach ([null, new \RuntimeException('The connection was lost.')] as $lost) {
            $driver = $this->driver($check, $lost);
            $driver->beginTransaction();
            $this->hooks->addEnded(fn () => $this->ran->append('ended'));
            $this->hooks->addBeforeCommit(static fn () => throw new \RuntimeException('The log is full.'));

            try {
                $driver->commit();
                self::fail('Nothing was thrown.');
            } catch (\RuntimeException $e) {
                self::assertSame(($lost ?? new \RuntimeException('The log is full.'))->getMessage(), $e->getMessage());
            }

            self::assertSame(['ended'], $this->ran->getArrayCopy());
            $this->ran->exchangeArray([]);
            $this->assertFollowsSavepoints($driver);
        }
    }

    /**
     * After every reset of what waits, savepoints are followed as in a new connection.
     */
    public function testAfterADiscardSavepointsAreFollowed(): void
    {
        $driver = $this->driver(true);
        $driver->beginTransaction();
        $driver->exec('SAVEPOINT a');
        $this->hooks->addBeforeCommit(fn () => $this->ran->append('discarded'));
        $this->hooks->discard();
        $driver->rollBack();

        $this->assertFollowsSavepoints($driver);
        $this->assertFollowsSavepoints($driver);
    }

    /**
     * Keywords in any case and with any spacing, names quoted or not: unquoted names are folded to
     * lowercase, as the databases do, quoted ones are kept as they are.
     */
    public function testSavepointsAreReadAsTheDatabasesReadThem(): void
    {
        $driver = $this->driver(true);
        $driver->beginTransaction();
        $this->hooks->addBeforeCommit(fn () => $this->ran->append('kept'));

        foreach ([
            ["  savepoint Mine\n", 'rollback  to savepoint MINE;'],
            ['SAVEPOINT "mine"', 'ROLLBACK TO mine'],
            ['SAVEPOINT `mine`', 'ROLLBACK TO SAVEPOINT `mine`'],
        ] as [$set, $rollBack]) {
            $driver->exec($set);
            $this->hooks->addBeforeCommit(fn () => $this->ran->append('undone'));
            $driver->exec($rollBack);
            $driver->exec('release mine');
        }

        $driver->commit();

        self::assertSame(['kept'], $this->ran->getArrayCopy());
        self::assertSame([], $this->alerts->of(AuditFailed::class));
    }

    /**
     * A rollback to a savepoint of the name set twice undoes what came after the later one only.
     */
    public function testTheLatestSavepointOfANameIsRolledBackTo(): void
    {
        $driver = $this->driver(true);
        $driver->beginTransaction();
        $driver->exec('SAVEPOINT a');
        $this->hooks->addBeforeCommit(fn () => $this->ran->append('first'));
        $driver->exec('SAVEPOINT b');
        $driver->exec('SAVEPOINT a');
        $this->hooks->addBeforeCommit(fn () => $this->ran->append('undone'));
        $driver->exec('ROLLBACK TO SAVEPOINT a');
        $driver->commit();

        self::assertSame(['first'], $this->ran->getArrayCopy());
        self::assertSame([], $this->alerts->of(AuditFailed::class));
    }

    /**
     * A savepoint it never saw set: what waits is kept, each time, and the alert says so, as a failure
     * that refused nothing.
     */
    public function testASavepointNeverSeenSetKeepsWhatWaits(): void
    {
        $driver = $this->driver(true);
        $driver->beginTransaction();
        $this->hooks->addBeforeCommit(fn () => $this->ran->append('first'));
        $driver->exec('ROLLBACK TO SAVEPOINT unseen');
        $this->hooks->addBeforeCommit(fn () => $this->ran->append('second'));
        $driver->exec('ROLLBACK TO SAVEPOINT unseen');
        $driver->commit();

        self::assertSame(['first', 'second'], $this->ran->getArrayCopy());
        $alerts = $this->alerts->of(AuditFailed::class);
        self::assertCount(2, $alerts);
        self::assertSame([AuditFailed::SECURITY_LOG, 1, false], [$alerts[0]->log, $alerts[0]->records, $alerts[0]->refused]);
        self::assertSame(2, $alerts[1]->records);
    }

    /**
     * A callback that runs just before the commit may leave another: it runs in the same commit.
     */
    public function testWhatWaitsForTheCommitMayAddMore(): void
    {
        $driver = $this->driver(true);
        $driver->beginTransaction();
        $this->hooks->addBeforeCommit(function (): void {
            $this->ran->append('first');
            $this->hooks->addBeforeCommit(fn () => $this->ran->append('second'));
        });
        $driver->commit();

        self::assertSame(['first', 'second'], $this->ran->getArrayCopy());
    }

    /**
     * Released, rolled back to and committed as a new connection's would be: only what the savepoint
     * released kept is run.
     */
    private function assertFollowsSavepoints(WatchedConnection $driver): void
    {
        $this->ran->exchangeArray([]);
        $driver->beginTransaction();
        $driver->exec('SAVEPOINT a');
        $this->hooks->addBeforeCommit(fn () => $this->ran->append('released'));
        $driver->exec('RELEASE SAVEPOINT a');
        $driver->exec('SAVEPOINT b');
        $this->hooks->addBeforeCommit(fn () => $this->ran->append('undone'));
        $driver->exec('ROLLBACK TO SAVEPOINT b');
        $driver->commit();

        self::assertSame(['released'], $this->ran->getArrayCopy());
        self::assertSame([], $this->alerts->of(AuditFailed::class));
        $this->ran->exchangeArray([]);
    }

    /**
     * A watched connection over a driver that does nothing, with the lock-order check on or off.
     */
    private function driver(bool $check, ?\Throwable $failedRollback = null): WatchedConnection
    {
        $inner = self::createStub(Connection::class);
        $inner->method('exec')->willReturn(0);

        if (null !== $failedRollback) {
            $inner->method('rollBack')->willThrowException($failedRollback);
        }

        $previous = getenv(LockOrder::VARIABLE);
        putenv($check ? LockOrder::VARIABLE.'=check' : LockOrder::VARIABLE);

        try {
            $this->hooks = new CommitHooks();
            $this->hooks->attach(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), new NullLogger(), new AuditAlarm($this->alerts, new NullLogger()));
        } finally {
            putenv(false === $previous ? LockOrder::VARIABLE : LockOrder::VARIABLE.'='.$previous);
        }

        $this->hooks->watch();

        return new WatchedConnection($inner, $this->hooks);
    }
}
