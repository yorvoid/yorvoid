<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\Dbal;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * The driver connection seen through TransactionMiddleware. DBAL calls the driver's commit() and
 * rollBack() only for the outermost transaction; nested ones are savepoints.
 *
 * @internal
 */
final class WatchedConnection extends AbstractConnectionMiddleware
{
    /** Whether a transaction of a callback's is open on the driver (D-43) */
    private bool $apart = false;

    public function __construct(
        Connection $connection,
        private readonly CommitHooks $hooks,
    ) {
        parent::__construct($connection);
        // A new connection, e.g. after a lost one was closed: no transaction of the old one is open.
        $hooks->lockOrder?->reset();
    }

    public function commit(): void
    {
        // A callback's commit() at DBAL's level 1 ends its own transaction, not the application's: no hooks.
        if ($this->apart) {
            $this->apart = false;
            $this->hooks->lockOrder?->end();
            parent::commit();

            return;
        }

        // What must be in the transaction, written last: if it fails, nothing is committed (D-52).
        try {
            $this->hooks->beforeCommit();
        } catch (\Throwable $failure) {
            try {
                $this->hooks->lockOrder?->end();
                parent::rollBack();
            } finally {
                $this->hooks->failedBeforeCommit($this);
            }

            throw $failure;
        }

        $this->hooks->lockOrder?->end();
        parent::commit();
        $this->hooks->committed($this);
    }

    public function beginTransaction(): void
    {
        parent::beginTransaction();
        $this->hooks->lockOrder?->begin();
    }

    public function prepare(string $sql): Statement
    {
        $this->hooks->lockOrder?->saw($sql);

        return parent::prepare($sql);
    }

    public function exec(string $sql): int|string
    {
        $this->hooks->lockOrder?->saw($sql);
        $affected = parent::exec($sql);
        $this->followSavepoints($sql);

        return $affected;
    }

    public function query(string $sql): Result
    {
        $this->hooks->lockOrder?->saw($sql);
        $result = parent::query($sql);
        $this->followSavepoints($sql);

        return $result;
    }

    /**
     * Savepoints, DBAL's for its nested transactions and the application's own: what waits for the
     * commit follows them (D-52). An unquoted name is folded to lowercase, as the databases do.
     */
    private function followSavepoints(string $sql): void
    {
        if (1 !== preg_match('/^\s*(SAVEPOINT|RELEASE|ROLLBACK\s+TO)\b/i', $sql, $statement)) {
            return;
        }

        if (1 !== preg_match('/^\s*(?:SAVEPOINT|RELEASE(?:\s+SAVEPOINT)?|ROLLBACK\s+TO(?:\s+SAVEPOINT)?)\s+("[^"]+"|`[^`]+`|[A-Za-z_][A-Za-z0-9_$]*)\s*;?\s*$/i', $sql, $named)) {
            $this->hooks->unreadableSavepoint($sql);

            return;
        }

        $name = \in_array($named[1][0], ['"', '`'], true) ? substr($named[1], 1, -1) : strtolower($named[1]);
        match (strtoupper(preg_replace('/\s+/', ' ', $statement[1]) ?? '')) {
            'SAVEPOINT' => $this->hooks->savepoint($name),
            'RELEASE' => $this->hooks->released($name),
            default => $this->hooks->rolledBackTo($name),
        };
    }

    public function rollBack(): void
    {
        // Likewise a callback's rollBack(): it would otherwise drop the callbacks still waiting.
        if ($this->apart) {
            $this->apart = false;
            $this->hooks->lockOrder?->end();
            parent::rollBack();

            return;
        }

        // Rolled back first: what waits for the end may open a transaction of its own (D-42).
        $this->hooks->lockOrder?->end();

        try {
            parent::rollBack();
        } finally {
            $this->hooks->rolledBack();
        }
    }

    /**
     * Begins a transaction on the driver that DBAL doesn't count, for a callback after a commit (D-43).
     */
    public function beginApart(): void
    {
        parent::beginTransaction();
        $this->apart = true;
        $this->hooks->lockOrder?->begin();
    }

    /**
     * Whether the callback's transaction is still open: false once the callback itself ended it.
     */
    public function isApart(): bool
    {
        return $this->apart;
    }

    /**
     * Commits it, without running callbacks: those it added run next, in CommitHooks' loop.
     */
    public function commitApart(): void
    {
        $this->apart = false;
        $this->hooks->lockOrder?->end();
        parent::commit();
    }

    public function rollBackApart(): void
    {
        if ($this->apart) {
            $this->apart = false;
            $this->hooks->lockOrder?->end();
            parent::rollBack();
        }
    }
}
