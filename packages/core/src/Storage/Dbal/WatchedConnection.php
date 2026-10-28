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
        $this->hooks->committed();
    }

    public function rollBack(): void
    {
        $this->hooks->rolledBack();
        parent::rollBack();
    }
}
