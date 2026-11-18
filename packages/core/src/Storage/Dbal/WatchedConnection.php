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
    public function __construct(
        Connection $connection,
        private readonly CommitHooks $hooks,
    ) {
        parent::__construct($connection);
    }

    public function commit(): void
    {
        parent::commit();
        $this->hooks->committed($this);
    }

    public function rollBack(): void
    {
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
    }

    /**
     * Commits it, without running callbacks: those it added run next, in CommitHooks' loop.
     */
    public function commitApart(): void
    {
        parent::commit();
    }

    public function rollBackApart(): void
    {
        parent::rollBack();
    }
}
