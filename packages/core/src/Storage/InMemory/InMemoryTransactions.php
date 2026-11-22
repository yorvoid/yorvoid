<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\InMemory;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use YorvoID\Core\Event\NoEventListeners;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\AuditAlarm;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\ForgetsWhatItRead;
use YorvoID\Core\Storage\RetryableConflict;
use YorvoID\Core\Storage\Transactions;

/**
 * Transactions for in-memory storage, used by tests and by applications without a database. A failed
 * unit of work puts the registered stores back as they were when it began and drops the callbacks it
 * added, like a database transaction or, when nested, a savepoint. What waits for the commit runs at
 * the end of the outermost unit, and its failure undoes it. Callbacks run when the outermost
 * transaction succeeds; a failing one is logged at error, not thrown (D-43).
 *
 * @api
 */
final class InMemoryTransactions implements Transactions
{
    /** @var list<\Closure(): (\Closure(): void)> For each store: take a snapshot, returning what restores it */
    private array $stores = [];

    private int $depth = 0;

    /** @var list<\Closure(): void> */
    private array $afterCommit = [];

    /** @var list<\Closure(): void> */
    private array $afterEnd = [];

    /** @var list<\Closure(): void> */
    private array $beforeCommit = [];

    private readonly AuditAlarm $alarm;

    /** @var list<ForgetsWhatItRead> */
    private readonly array $reads;

    private bool $retrying = false;

    /** On retrying()'s last attempt, which nothing runs again */
    private bool $final = false;

    /**
     * @param EventDispatcherInterface    $events Where an afterEnd() callback's failure is announced (AuditFailed)
     * @param LoggerInterface             $logger Where it's logged, at critical, and an afterCommit() callback's at error
     * @param iterable<ForgetsWhatItRead> $reads  What forgets what it read before work that conflicted runs again
     */
    public function __construct(EventDispatcherInterface $events = new NoEventListeners(), private readonly LoggerInterface $logger = new NullLogger(), iterable $reads = [])
    {
        $this->alarm = new AuditAlarm($events, $logger);
        $this->reads = array_values([...$reads]);
    }

    /**
     * @template T
     *
     * @param InMemoryStore<T> $store
     */
    public function register(InMemoryStore $store): void
    {
        $this->stores[] = static function () use ($store): \Closure {
            $snapshot = $store->snapshot();

            return static function () use ($store, $snapshot): void {
                $store->restore($snapshot);
            };
        };
    }

    public function run(\Closure $work): mixed
    {
        $outermost = 0 === $this->depth;
        $restorers = array_map(static fn (\Closure $take): \Closure => $take(), $this->stores);
        $waiting = \count($this->afterCommit);
        $before = \count($this->beforeCommit);
        ++$this->depth;

        try {
            $result = $work();

            // Just before the outermost commit, in it: a failure undoes the whole transaction.
            while ($outermost && [] !== $this->beforeCommit) {
                $callback = array_shift($this->beforeCommit);
                $callback();
            }
        } catch (\Throwable $exception) {
            foreach ($restorers as $restore) {
                $restore();
            }

            $this->afterCommit = \array_slice($this->afterCommit, 0, $waiting);
            $this->beforeCommit = $outermost ? [] : \array_slice($this->beforeCommit, 0, $before);

            // Run again from the start, it leaves its callbacks again: this attempt's go. Not the last's.
            if ($outermost && $this->retrying && !$this->final && $exception instanceof RetryableConflict) {
                $this->afterEnd = [];
            }

            throw $exception;
        } finally {
            --$this->depth;

            // Committed or rolled back: what waits for the end runs either way.
            while ($outermost && [] !== $this->afterEnd) {
                $callback = array_shift($this->afterEnd);
                $callback();
            }
        }

        while ($outermost && [] !== $this->afterCommit) {
            $this->reported(array_shift($this->afterCommit));
        }

        return $result;
    }

    /**
     * Memory doesn't conflict: work that throws a RetryableConflict of its own, as a test's does, is run
     * again as DbalTransactions would, without waiting.
     */
    public function retrying(\Closure $work): mixed
    {
        if (0 !== $this->depth || $this->retrying) {
            return $work();
        }

        $this->retrying = true;
        $this->final = false;

        try {
            for ($attempt = 1; $attempt < DbalTransactions::ATTEMPTS; ++$attempt) {
                try {
                    return $work();
                } catch (RetryableConflict) {
                    foreach ($this->reads as $read) {
                        $read->reset();
                    }
                }
            }

            $this->final = true;

            try {
                return $work();
            } catch (RetryableConflict $conflict) {
                throw new RetryableConflict($conflict->getPrevious() ?? $conflict, DbalTransactions::ATTEMPTS);
            }
        } finally {
            $this->retrying = false;
        }
    }

    /**
     * Memory has one process: nothing to wait for.
     */
    public function lockRealm(Realm $realm): void
    {
        if (0 === $this->depth) {
            throw new IntegratorError('A realm was locked outside a transaction.', 'Lock it inside Transactions::run(), before reading what the change depends on.', 'concepts/storage.md#locks');
        }
    }

    public function beforeCommit(\Closure $callback): bool
    {
        if (0 === $this->depth) {
            return false;
        }

        $this->beforeCommit[] = $callback;

        return true;
    }

    public function afterCommit(\Closure $callback): void
    {
        if (0 === $this->depth) {
            $this->reported($callback);

            return;
        }

        $this->afterCommit[] = $callback;
    }

    public function afterEnd(\Closure $callback): void
    {
        $callback = $this->alarm->guard($callback);

        if (0 === $this->depth) {
            $callback();

            return;
        }

        $this->afterEnd[] = $callback;
    }

    /**
     * @param \Closure(): void $callback
     */
    private function reported(\Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            $this->logger->error('What YorvoID ran after a commit failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
