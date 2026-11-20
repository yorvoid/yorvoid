<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use YorvoID\Core\Event\NoEventListeners;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\AuditAlarm;
use YorvoID\Core\Storage\ForgetsWhatItRead;
use YorvoID\Core\Storage\RetryableConflict;
use YorvoID\Core\Storage\RetryScope;
use YorvoID\Core\Storage\Transactions;

/**
 * Transactions on the application's DBAL connection. With the connection's TransactionMiddleware,
 * after-commit callbacks wait for the outermost transaction, also one the application opened; after
 * the application's commit, each runs in a transaction of its own (D-43).
 *
 * @api
 */
final readonly class DbalTransactions implements Transactions
{
    /** How many times retrying() runs work that conflicts, in all */
    public const int ATTEMPTS = 3;

    private AuditAlarm $alarm;

    private LoggerInterface $logger;

    /** @var list<ForgetsWhatItRead> */
    private array $reads;

    private \Closure $wait;

    private \Closure $random;

    private RetryScope $retrying;

    /**
     * @param EventDispatcherInterface       $events Where an afterEnd() callback's failure is announced (AuditFailed)
     * @param LoggerInterface                $logger Where it's logged, at critical, and an afterCommit() callback's at error
     * @param iterable<ForgetsWhatItRead>    $reads  What forgets what it read before work that conflicted runs again
     * @param (\Closure(int): void)|null     $wait   How it waits before an attempt, in microseconds: usleep() if not given
     * @param (\Closure(int, int): int)|null $random How long, between two bounds: random_int() if not given
     */
    public function __construct(
        private Connection $connection,
        private ?TransactionMiddleware $middleware = null,
        EventDispatcherInterface $events = new NoEventListeners(),
        LoggerInterface $logger = new NullLogger(),
        iterable $reads = [],
        ?\Closure $wait = null,
        ?\Closure $random = null,
    ) {
        $this->reads = array_values([...$reads]);
        $this->wait = $wait ?? usleep(...);
        $this->random = $random ?? random_int(...);
        $this->retrying = new RetryScope();
        $this->alarm = new AuditAlarm($events, $logger);
        $this->logger = $logger;
        $middleware?->hooks()->attach($connection, $logger, $this->alarm);
    }

    public function run(\Closure $work): mixed
    {
        $hooks = $this->middleware?->hooks();

        if (null === $hooks) {
            try {
                return $this->connection->transactional($work);
            } catch (DeadlockException $conflict) {
                throw new RetryableConflict($conflict);
            }
        }

        $waiting = $hooks->count();
        $outermost = !$this->connection->isTransactionActive();
        // Run again from the start after a conflict, it leaves its callbacks again: this attempt's go,
        // before the rollback runs them. Not on the last attempt, which nothing runs again.
        $discards = $outermost && $this->retrying->active && !$this->retrying->final;

        if ($outermost) {
            $hooks->defer(true);
        }

        try {
            $result = $this->connection->transactional($discards ? static function () use ($work, $hooks): mixed {
                try {
                    return $work();
                } catch (DeadlockException|RetryableConflict $conflict) {
                    $hooks->discard();

                    throw $conflict;
                }
            } : $work);
        } catch (\Throwable $exception) {
            // The outermost rollback drops every callback; a failed nested one only its own.
            $hooks->truncate($waiting);

            // A lost connection gets no rollback, but the server ended the transaction: DBAL's state is
            // unknown, so it forgets it and reconnects on its next use, for the callbacks too.
            if ($outermost && $exception instanceof ConnectionLost) {
                $this->connection->close();
            }

            // A commit that failed, or a lost connection, ended the transaction without a rollback
            // reaching the driver's hooks: what waits for the end runs now, or, if a conflict runs the
            // work again from the start, goes with the attempt.
            if ($outermost && 0 === $this->connection->getTransactionNestingLevel()) {
                $discards && ($exception instanceof DeadlockException || $exception instanceof RetryableConflict) ? $hooks->discard() : $hooks->rolledBack();
            }

            throw $exception instanceof DeadlockException ? new RetryableConflict($exception) : $exception;
        } finally {
            if ($outermost) {
                $hooks->defer(false);
            }
        }

        // Now that DBAL has finished committing, callbacks can open transactions of their own. A failure
        // is reported, not thrown: the work is committed (D-43).
        if ($outermost) {
            $hooks->runCommitted();
        }

        return $result;
    }

    public function retrying(\Closure $work): mixed
    {
        // An application's transaction: its work isn't YorvoID's to run again. Nor is work already retried.
        if ($this->connection->isTransactionActive() || $this->retrying->active) {
            return $work();
        }

        $this->retrying->active = true;
        $this->retrying->final = false;

        try {
            for ($attempt = 1; $attempt < self::ATTEMPTS; ++$attempt) {
                try {
                    return $work();
                } catch (RetryableConflict) {
                    foreach ($this->reads as $read) {
                        $read->reset();
                    }

                    ($this->wait)($this->pause($attempt));
                }
            }

            $this->retrying->final = true;

            try {
                return $work();
            } catch (RetryableConflict $conflict) {
                throw new RetryableConflict($conflict->getPrevious() ?? $conflict, self::ATTEMPTS);
            }
        } finally {
            $this->retrying->active = false;
        }
    }

    /**
     * How long to wait before the next attempt, in microseconds: 10 to 50 ms, then 50 to 150 ms, so that
     * the two transactions that met don't meet again.
     */
    private function pause(int $attempt): int
    {
        return 1 === $attempt ? ($this->random)(10_000, 50_000) : ($this->random)(50_000, 150_000);
    }

    public function afterCommit(\Closure $callback): void
    {
        if (!$this->connection->isTransactionActive()) {
            try {
                $callback();
            } catch (\Throwable $e) {
                $this->logger->error('What YorvoID ran after a commit failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            }

            return;
        }

        $hooks = $this->middleware?->hooks();

        if (null === $hooks || !$hooks->isWatching()) {
            throw new IntegratorError('YorvoID can\'t tell when this connection\'s transactions commit.', 'Add a TransactionMiddleware to the connection\'s configuration before the connection is created, and pass it to DbalTransactions (the Symfony bridge does this).', 'concepts/storage.md#transactions');
        }

        $hooks->add($callback);
    }

    public function afterEnd(\Closure $callback): void
    {
        $hooks = $this->middleware?->hooks();

        // No transaction, or none whose end YorvoID can see: now (D-42).
        if (!$this->connection->isTransactionActive() || null === $hooks || !$hooks->isWatching()) {
            $this->alarm->guard($callback)();

            return;
        }

        // Unguarded: CommitHooks rolls back the callback's own transaction before it raises the alert.
        $hooks->addEnded($callback);
    }
}
