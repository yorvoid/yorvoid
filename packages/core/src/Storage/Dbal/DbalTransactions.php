<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\Dbal;

use Doctrine\DBAL\Connection;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Storage\Transactions;

/**
 * Transactions on the application's DBAL connection. With the connection's TransactionMiddleware,
 * after-commit callbacks wait for the outermost transaction, also one the application opened.
 *
 * @api
 */
final readonly class DbalTransactions implements Transactions
{
    public function __construct(
        private Connection $connection,
        private ?TransactionMiddleware $middleware = null,
    ) {
    }

    public function run(\Closure $work): mixed
    {
        $hooks = $this->middleware?->hooks();

        if (null === $hooks) {
            return $this->connection->transactional($work);
        }

        $waiting = $hooks->count();
        $outermost = !$this->connection->isTransactionActive();

        if ($outermost) {
            $hooks->defer(true);
        }

        try {
            $result = $this->connection->transactional($work);
        } catch (\Throwable $exception) {
            // The outermost rollback drops every callback; a failed nested one only its own.
            $hooks->truncate($waiting);

            throw $exception;
        } finally {
            if ($outermost) {
                $hooks->defer(false);
            }
        }

        // Now that DBAL has finished committing, callbacks can open transactions of their own.
        if ($outermost) {
            $hooks->runCommitted();
        }

        return $result;
    }

    public function afterCommit(\Closure $callback): void
    {
        if (!$this->connection->isTransactionActive()) {
            $callback();

            return;
        }

        $hooks = $this->middleware?->hooks();

        if (null === $hooks || !$hooks->isWatching()) {
            throw new IntegratorError('YorvoID can\'t tell when this connection\'s transactions commit.', 'Add a TransactionMiddleware to the connection\'s configuration before the connection is created, and pass it to DbalTransactions (the Symfony bridge does this).', 'concepts/storage.md#transactions');
        }

        $hooks->add($callback);
    }
}
