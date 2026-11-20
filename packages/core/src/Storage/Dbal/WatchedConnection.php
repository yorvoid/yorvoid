<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\Dbal;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

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
    }

    public function commit(): void
    {
        // A callback's commit() at DBAL's level 1 ends its own transaction, not the application's: no hooks.
        if ($this->apart) {
            $this->apart = false;
            parent::commit();

            return;
        }

        // What must be in the transaction, written last: if it fails, nothing is committed (D-52).
        try {
            $this->hooks->beforeCommit();
        } catch (\Throwable $failure) {
            try {
                parent::rollBack();
            } finally {
                $this->hooks->failedBeforeCommit($this);
            }

            throw $failure;
        }

        parent::commit();
        $this->hooks->committed($this);
    }

    public function exec(string $sql): int|string
    {
        $affected = parent::exec($sql);

        // Savepoints, DBAL's for its nested transactions and the application's own: what waits for the
        // commit follows them (D-52).
        if (1 === preg_match('/^\s*(SAVEPOINT|RELEASE|ROLLBACK\s+TO)\b/i', $sql, $statement)) {
            if (1 !== preg_match('/^\s*(?:SAVEPOINT|RELEASE(?:\s+SAVEPOINT)?|ROLLBACK\s+TO(?:\s+SAVEPOINT)?)\s+("[^"]+"|`[^`]+`|[A-Za-z_][A-Za-z0-9_$]*)\s*;?\s*$/i', $sql, $named)) {
                $this->hooks->unreadableSavepoint($sql);
            } else {
                $name = trim($named[1], '"`');
                match (strtoupper(preg_replace('/\s+/', ' ', $statement[1]) ?? '')) {
                    'SAVEPOINT' => $this->hooks->savepoint($name),
                    'RELEASE' => $this->hooks->released($name),
                    default => $this->hooks->rolledBackTo($name),
                };
            }
        }

        return $affected;
    }

    public function rollBack(): void
    {
        // Likewise a callback's rollBack(): it would otherwise drop the callbacks still waiting.
        if ($this->apart) {
            $this->apart = false;
            parent::rollBack();

            return;
        }

        // Rolled back first: what waits for the end may open a transaction of its own (D-42).
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
        parent::commit();
    }

    public function rollBackApart(): void
    {
        if ($this->apart) {
            $this->apart = false;
            parent::rollBack();
        }
    }
}
