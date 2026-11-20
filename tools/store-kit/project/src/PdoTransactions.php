<?php

declare(strict_types=1);

namespace Acme\Storage;

use YorvoID\Core\Realm;
use YorvoID\Core\Storage\RetryableConflict;
use YorvoID\Core\Storage\Transactions;

/**
 * Transactions on one PDO connection: a nested unit of work is a savepoint, and the callbacks wait for
 * the outermost commit.
 */
final class PdoTransactions implements Transactions
{
    private int $depth = 0;

    /** @var list<\Closure(): void> */
    private array $callbacks = [];

    /** @var list<\Closure(): void> For the end, committed or rolled back */
    private array $ended = [];

    private bool $retrying = false;

    /** Under retrying(), on an attempt a conflict runs again */
    private bool $again = false;

    public function __construct(
        private readonly \PDO $pdo,
    ) {
    }

    public function run(\Closure $work): mixed
    {
        $waiting = \count($this->callbacks);
        $this->begin();

        try {
            $result = $work();
        } catch (\Throwable $exception) {
            $this->rollBack();
            // The outermost rollback drops every callback; a failed nested unit only its own.
            $this->callbacks = \array_slice($this->callbacks, 0, $waiting);

            // Run again from the start, the work leaves its callbacks for the end again: this attempt's go.
            if (0 === $this->depth && $this->again && $exception instanceof RetryableConflict) {
                $this->ended = [];
            }

            $this->ended();

            throw $exception;
        }

        $this->commit();

        if (0 === $this->depth) {
            // Taken first: a callback may open a transaction of its own, which runs its own callbacks.
            $callbacks = $this->callbacks;
            $this->callbacks = [];

            foreach ($callbacks as $callback) {
                $callback();
            }
        }

        $this->ended();

        return $result;
    }

    /**
     * An SQLite file serializes its writers, so its own transactions don't deadlock, but work that throws
     * a RetryableConflict runs again from the start: up to 3 attempts, and never inside an open
     * transaction, whose work isn't this unit's own. A database that conflicts would wrap its error in a
     * RetryableConflict in run(), and wait a little between attempts.
     */
    public function retrying(\Closure $work): mixed
    {
        if (0 !== $this->depth || $this->retrying) {
            return $work();
        }

        $this->retrying = true;

        try {
            for ($attempt = 1;; ++$attempt) {
                $this->again = $attempt < 3;

                try {
                    return $work();
                } catch (RetryableConflict $conflict) {
                    if (!$this->again) {
                        throw $conflict;
                    }
                }
            }
        } finally {
            $this->retrying = $this->again = false;
        }
    }

    /**
     * SQLite writes one transaction at a time, from its first write: nothing more to lock. On a database
     * with row locks, write a row of the realm's here (INSERT ... ON CONFLICT DO UPDATE of a counter),
     * the first lock of the unit of work.
     */
    public function lockRealm(Realm $realm): void
    {
        if (0 === $this->depth) {
            throw new \LogicException('Lock a realm inside a transaction.');
        }
    }

    /**
     * Nothing waits for the commit here: the security log appends at once, and holds its lock for the
     * rest of the transaction. Implement it, as afterCommit() but just before the commit, to shorten that.
     */
    public function beforeCommit(\Closure $callback): bool
    {
        return false;
    }

    public function afterEnd(\Closure $callback): void
    {
        // A failure goes no further than its own callback: log it in a project of your own.
        $guarded = static function () use ($callback): void {
            try {
                $callback();
            } catch (\Throwable) {
            }
        };

        if (0 === $this->depth) {
            $guarded();

            return;
        }

        $this->ended[] = $guarded;
    }

    /**
     * Once the outermost unit has ended, whichever way: what waits for the end.
     */
    private function ended(): void
    {
        while (0 === $this->depth && [] !== $this->ended) {
            $callback = array_shift($this->ended);
            $callback();
        }
    }

    public function afterCommit(\Closure $callback): void
    {
        if (0 === $this->depth) {
            $callback();

            return;
        }

        $this->callbacks[] = $callback;
    }

    private function begin(): void
    {
        0 === $this->depth ? $this->pdo->beginTransaction() : $this->pdo->exec('SAVEPOINT unit_'.$this->depth);
        ++$this->depth;
    }

    private function commit(): void
    {
        --$this->depth;
        0 === $this->depth ? $this->pdo->commit() : $this->pdo->exec('RELEASE SAVEPOINT unit_'.$this->depth);
    }

    private function rollBack(): void
    {
        --$this->depth;

        if (0 === $this->depth) {
            $this->pdo->rollBack();

            return;
        }

        // Rolling back to a savepoint keeps it: release it too.
        $this->pdo->exec('ROLLBACK TO SAVEPOINT unit_'.$this->depth);
        $this->pdo->exec('RELEASE SAVEPOINT unit_'.$this->depth);
    }
}
