<?php

declare(strict_types=1);

namespace Acme\Storage;

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

        return $result;
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
