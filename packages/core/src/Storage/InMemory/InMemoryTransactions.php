<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\InMemory;

use YorvoID\Core\Storage\Transactions;

/**
 * Transactions for in-memory storage, used by tests and by applications without a database. A failed
 * unit of work puts the registered stores back as they were when it began and drops the callbacks it
 * added, like a database transaction or, when nested, a savepoint. Callbacks run when the outermost
 * transaction succeeds.
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
        ++$this->depth;

        try {
            $result = $work();
        } catch (\Throwable $exception) {
            foreach ($restorers as $restore) {
                $restore();
            }

            $this->afterCommit = \array_slice($this->afterCommit, 0, $waiting);

            throw $exception;
        } finally {
            --$this->depth;
        }

        while ($outermost && [] !== $this->afterCommit) {
            $callback = array_shift($this->afterCommit);
            $callback();
        }

        return $result;
    }

    public function afterCommit(\Closure $callback): void
    {
        if (0 === $this->depth) {
            $callback();

            return;
        }

        $this->afterCommit[] = $callback;
    }
}
