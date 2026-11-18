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
        parent::commit();

        // A callback's commit() at DBAL's level 1 ends its own transaction, not the application's: no hooks.
        if ($this->apart) {
            $this->apart = false;

            return;
        }

        $this->hooks->committed($this);
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
