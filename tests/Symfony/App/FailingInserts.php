<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Statement;

/**
 * Fault injection at the database (AUD-006): every connection's INSERTs into the tables named in
 * $tables fail, as a full disk or a lost connection would. Static, since DoctrineBundle gives every
 * connection its own copy of a middleware; a test that sets it clears it.
 */
final class FailingInserts implements Middleware
{
    /** @var list<string> Table names, as they appear in the SQL */
    public static array $tables = [];

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        FailingInserts::check($sql);

                        return parent::prepare($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        FailingInserts::check($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }

    public static function check(string $sql): void
    {
        foreach (self::$tables as $table) {
            if (1 === preg_match('/^\s*INSERT INTO\s+\S*'.preg_quote($table, '/').'\b/i', $sql)) {
                throw new \RuntimeException(\sprintf('Injected: the insert into %s failed.', $table));
            }
        }
    }
}
