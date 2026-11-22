<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use YorvoID\Core\Event\NoEventListeners;
use YorvoID\Core\Log\AuditAlarm;
use YorvoID\Core\Log\Event\AuditFailed;
use YorvoID\Core\Storage\StorageLocation;

/**
 * Callbacks waiting for the connection's outermost transaction to end. TransactionMiddleware tells it
 * when that transaction commits or rolls back, also when the application started it.
 *
 * A callback's failure is reported, never thrown: the transaction it waited for has ended, and an
 * exception then would say otherwise (D-43). After a commit, it's logged at error; after the end, it
 * raises the audit alert.
 *
 * @internal
 */
final class CommitHooks
{
    /** @var list<\Closure(): void> */
    private array $callbacks = [];

    /** @var list<\Closure(): void> Callbacks for the end, committed or rolled back */
    private array $ended = [];

    /**
     * Callbacks just before the commit, in savepoint frames: the transaction's own first, then one per
     * savepoint open, by name, as the database keeps them.
     *
     * @var non-empty-list<array{?string, list<\Closure(): void>}>
     */
    private array $beforeCommit = [[null, []]];

    private bool $watching = false;

    /** Whether a commit should wait for runCommitted() rather than run the callbacks at once */
    private bool $deferring = false;

    private bool $committed = false;

    private ?Connection $connection = null;

    /** Checks the order of the transaction's locks, in the tests (YORVOID_LOCK_ORDER=check) */
    public private(set) ?LockOrder $lockOrder = null;

    private LoggerInterface $logger;

    private AuditAlarm $alarm;

    public function __construct()
    {
        $this->logger = new NullLogger();
        $this->alarm = new AuditAlarm(new NoEventListeners(), $this->logger);
    }

    /**
     * The DBAL connection over the watched one, and where a callback's failure goes.
     */
    public function attach(Connection $connection, LoggerInterface $logger, AuditAlarm $alarm, StorageLocation $location = new StorageLocation()): void
    {
        $this->connection = $connection;
        $this->lockOrder ??= LockOrder::fromEnvironment($location);
        $this->logger = $logger;
        $this->alarm = $alarm;
    }

    public function watch(): void
    {
        $this->watching = true;
    }

    public function isWatching(): bool
    {
        return $this->watching;
    }

    /**
     * @param \Closure(): void $callback
     */
    public function add(\Closure $callback): void
    {
        $this->callbacks[] = $callback;
    }

    /**
     * @param \Closure(): void $callback
     */
    public function addEnded(\Closure $callback): void
    {
        $this->ended[] = $callback;
    }

    /**
     * @param \Closure(): void $callback
     */
    public function addBeforeCommit(\Closure $callback): void
    {
        $this->beforeCommit[array_key_last($this->beforeCommit)][1][] = $callback;
    }

    /**
     * A savepoint was set: what's added from now on is undone with it.
     */
    public function savepoint(string $name): void
    {
        $this->beforeCommit[] = [$name, []];
    }

    /**
     * The savepoint was released: what waited in it, and in those set after it, now waits in the one
     * it was set in.
     */
    public function released(string $name): void
    {
        $frame = $this->frame($name);

        if (null === $frame) {
            $this->unknown('released', $name);

            return;
        }

        $merged = array_merge(...array_column(\array_slice($this->beforeCommit, $frame), 1));
        $kept = \array_slice($this->beforeCommit, 0, $frame);
        \assert([] !== $kept);
        [$name, $waiting] = $kept[\count($kept) - 1];
        $kept[\count($kept) - 1] = [$name, [...$waiting, ...$merged]];
        $this->beforeCommit = $kept;
    }

    /**
     * Rolled back to the savepoint: what was added since it was set won't be committed. The savepoint
     * itself stays, as the database keeps it.
     */
    public function rolledBackTo(string $name): void
    {
        $frame = $this->frame($name);

        if (null === $frame) {
            $this->unknown('rolled back to', $name);

            return;
        }

        $kept = \array_slice($this->beforeCommit, 0, $frame);
        $kept[] = [$name, []];
        $this->beforeCommit = $kept;
    }

    /**
     * A savepoint statement that can't be read, e.g. a name with spaces: which of what waits it undoes
     * can't be told. It's kept, and the audit alert says so, rather than guessing (D-52).
     */
    public function unreadableSavepoint(string $sql): void
    {
        $this->unknown('changed', $sql);
    }

    /**
     * The latest open savepoint of the name, as the database finds it.
     */
    private function frame(string $name): ?int
    {
        // The transaction's own frame has no name: never found.
        return array_find_key(array_reverse($this->beforeCommit, true), static fn (array $frame): bool => $frame[0] === $name);
    }

    private function unknown(string $what, string $savepoint): void
    {
        $waiting = \count(array_merge(...array_column($this->beforeCommit, 1)));

        if (0 < $waiting) {
            $this->alarm->raise(new AuditFailed(AuditFailed::SECURITY_LOG, null, $waiting, false, new \LogicException(\sprintf('The transaction %s the savepoint "%s", which YorvoID didn\'t see set: it kept the security log entries it waited to append at the commit, which may include some of the work undone.', $what, $savepoint))));
        }
    }

    /**
     * The driver is about to commit: runs what waits for that, in the order it was added. A failure is
     * thrown, for the commit to roll back.
     */
    public function beforeCommit(): void
    {
        $waiting = array_merge(...array_column($this->beforeCommit, 1));
        $this->beforeCommit = [[null, []]];

        if ([] === $waiting) {
            return;
        }

        foreach ($waiting as $callback) {
            $callback();
        }

        // Callbacks may leave callbacks of their own: those run next, in the same commit.
        $this->beforeCommit();
    }

    /**
     * What waited before the commit failed, and the driver rolled back: nothing of the transaction is
     * committed. DBAL still counts it as open until its commit() has thrown, so what waits for the end
     * runs in a transaction of its own on the driver, as after a commit (D-43).
     */
    public function failedBeforeCommit(WatchedConnection $driver): void
    {
        $this->callbacks = [];
        $this->beforeCommit = [[null, []]];
        $connection = $this->connection;
        $this->runEnded(null === $connection ? null : fn (\Closure $callback) => $this->apart($connection, $driver, $callback));
    }

    /**
     * How many callbacks are waiting, to drop those a failed unit of work added (see truncate()).
     */
    public function count(): int
    {
        return \count($this->callbacks);
    }

    /**
     * Drops the callbacks added after the given count: a nested unit of work (a savepoint) failed, so
     * what it did won't be committed even if the outer transaction is.
     */
    public function truncate(int $count): void
    {
        $this->callbacks = \array_slice($this->callbacks, 0, $count);
    }

    /**
     * Drops everything waiting, for the commit and for the end: the outermost transaction conflicted,
     * and its work runs again from the start and leaves them again (D-52). What waited just before the
     * commit already went with the transaction's end: its rollback, the failure before the commit, or
     * the commit that ran it.
     */
    public function discard(): void
    {
        $this->callbacks = [];
        $this->ended = [];
    }

    /**
     * Makes the next commit wait for runCommitted(): YorvoID's own unit of work runs the callbacks once
     * DBAL has finished committing.
     */
    public function defer(bool $deferring): void
    {
        $this->deferring = $deferring;
    }

    /**
     * The driver committed. DBAL still counts the transaction as open until this returns, so a callback
     * that opened one would get a savepoint of a transaction that no longer exists (ARC-0059). Unless
     * YorvoID's own unit of work deferred them, each runs in a transaction of its own on the driver,
     * where DBAL's savepoints are real.
     */
    public function committed(WatchedConnection $driver): void
    {
        if ($this->deferring) {
            $this->committed = true;

            return;
        }

        // Without the DBAL connection (no DbalTransactions attached), nothing was queued through YorvoID.
        $connection = $this->connection;
        $apart = null === $connection ? null : fn (\Closure $callback) => $this->apart($connection, $driver, $callback);
        $this->run($apart);
        $this->runEnded($apart);
    }

    /**
     * Runs the callbacks of a deferred commit, if there was one.
     */
    public function runCommitted(): void
    {
        if ($this->committed) {
            $this->committed = false;
            $this->run();
            $this->runEnded();
        }
    }

    public function rolledBack(): void
    {
        $this->callbacks = [];
        $this->beforeCommit = [[null, []]];
        // DBAL counts no transaction once it rolls back, before the driver does: they can open one.
        $this->runEnded();
    }

    /**
     * @param (\Closure(\Closure(): void): void)|null $runner How each callback runs, if not just called
     */
    private function runEnded(?\Closure $runner = null): void
    {
        while ([] !== $this->ended) {
            $callbacks = $this->ended;
            $this->ended = [];

            foreach ($callbacks as $callback) {
                try {
                    null === $runner ? $callback() : $runner($callback);
                } catch (\Throwable $e) {
                    $this->alarm->raise(new AuditFailed(AuditFailed::AFTER_END, null, 1, false, $e));
                }
            }
        }
    }

    /**
     * @param (\Closure(\Closure(): void): void)|null $runner How each callback runs, if not just called
     */
    private function run(?\Closure $runner = null): void
    {
        // Callbacks may open transactions and add callbacks of their own: those run afterwards.
        while ([] !== $this->callbacks) {
            $callbacks = $this->callbacks;
            $this->callbacks = [];

            foreach ($callbacks as $callback) {
                try {
                    null === $runner ? $callback() : $runner($callback);
                } catch (\Throwable $e) {
                    $this->logger->error('What YorvoID ran after a commit failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
                }
            }
        }
    }

    /**
     * Runs the callback in a transaction of its own on the driver. If it fails, or leaves a transaction
     * of DBAL's open, DBAL's savepoints are rolled back first, while they still exist, then the
     * driver's transaction: the connection stays usable, e.g. in a long-running worker. If that fails
     * too, the DBAL connection is closed, so it reconnects in a known state on its next use.
     *
     * Once DBAL counts no transaction (a callback ended the application's, as DBAL sees it, or the
     * connection was closed), the next callbacks run as after YorvoID's own commit: just called.
     *
     * @param \Closure(): void $callback
     */
    private function apart(Connection $connection, WatchedConnection $driver, \Closure $callback): void
    {
        if (0 === $connection->getTransactionNestingLevel()) {
            $callback();

            return;
        }

        $driver->beginApart();

        try {
            $callback();

            if ($connection->getTransactionNestingLevel() > 1) {
                throw new \LogicException('A callback after the commit left a transaction open.');
            }

            // DBAL's commit() or rollBack() at its level 1 ended it (WatchedConnection).
            if (!$driver->isApart()) {
                throw new \LogicException('A callback after the commit ended the transaction it ran in.');
            }

            // What it left for just before its commit, e.g. its security log entries: in its transaction.
            $this->beforeCommit();
        } catch (\Throwable $e) {
            $this->beforeCommit = [[null, []]];
            $this->unwind($connection, $driver, $e);
        }

        $driver->commitApart();
    }

    /**
     * Rolls back what a failed callback left: DBAL's savepoints, then its own transaction on the
     * driver. Throws the callback's failure, or, if rolling back failed too, both.
     *
     * @return never
     */
    private function unwind(Connection $connection, WatchedConnection $driver, \Throwable $failure): void
    {
        try {
            try {
                while ($connection->getTransactionNestingLevel() > 1) {
                    $connection->rollBack();
                }
            } finally {
                $driver->rollBackApart();
            }
        } catch (\Throwable $rollback) {
            // The connection's state is unknown: DBAL forgets it, and reconnects on its next use.
            $connection->close();

            throw new CallbackRollbackFailed($failure, $rollback);
        }

        throw $failure;
    }
}
