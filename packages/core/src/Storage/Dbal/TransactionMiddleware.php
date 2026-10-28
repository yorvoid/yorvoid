<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\Dbal;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * A DBAL middleware that tells YorvoID when the connection's outermost transaction commits or rolls
 * back, so "-ed" events wait for the application's own transaction (PLT-042). DBAL applies middlewares
 * when it creates the connection, so add it to the configuration first; the Symfony bridge does that.
 *
 * ```php
 * $middleware = new TransactionMiddleware();
 * $configuration->setMiddlewares([...$configuration->getMiddlewares(), $middleware]);
 * $connection = DriverManager::getConnection($params, $configuration);
 * $transactions = new DbalTransactions($connection, $middleware);
 * ```
 *
 * One middleware per connection.
 *
 * @api
 */
final readonly class TransactionMiddleware implements Middleware
{
    private CommitHooks $hooks;

    public function __construct()
    {
        $this->hooks = new CommitHooks();
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->hooks) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly CommitHooks $hooks)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Driver\Connection
            {
                $this->hooks->watch();

                return new WatchedConnection(parent::connect($params), $this->hooks);
            }
        };
    }

    /**
     * @internal
     */
    public function hooks(): CommitHooks
    {
        return $this->hooks;
    }
}
