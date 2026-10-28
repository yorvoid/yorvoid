<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\Dbal;

/**
 * Callbacks waiting for the connection's outermost transaction to end. TransactionMiddleware tells it
 * when that transaction commits or rolls back, also when the application started it.
 *
 * @internal
 */
final class CommitHooks
{
    /** @var list<\Closure(): void> */
    private array $callbacks = [];

    private bool $watching = false;

    /** Whether a commit should wait for runCommitted() rather than run the callbacks at once */
    private bool $deferring = false;

    private bool $committed = false;

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
     * Makes the next commit wait for runCommitted(): DBAL still counts the transaction as open while
     * the driver commits, so a callback that opened one then would get a savepoint of a transaction that
     * no longer exists.
     */
    public function defer(bool $deferring): void
    {
        $this->deferring = $deferring;
    }

    public function committed(): void
    {
        if ($this->deferring) {
            $this->committed = true;

            return;
        }

        $this->run();
    }

    /**
     * Runs the callbacks of a deferred commit, if there was one.
     */
    public function runCommitted(): void
    {
        if ($this->committed) {
            $this->committed = false;
            $this->run();
        }
    }

    public function rolledBack(): void
    {
        $this->callbacks = [];
    }

    private function run(): void
    {
        // Callbacks may open transactions and add callbacks of their own: those run afterwards.
        while ([] !== $this->callbacks) {
            $callbacks = $this->callbacks;
            $this->callbacks = [];

            foreach ($callbacks as $callback) {
                $callback();
            }
        }
    }
}
