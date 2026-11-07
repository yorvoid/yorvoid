<?php

declare(strict_types=1);

namespace Acme\Storage\Tests;

use Acme\Storage\PdoFlowStore;
use Acme\Storage\PdoPrincipalStore;
use Acme\Storage\PdoThrottleStore;
use Acme\Storage\PdoTransactions;
use PHPUnit\Framework\TestCase;

/**
 * The project's own base: a new SQLite database with its tables for each test, and its transactions.
 */
abstract class SqliteTestCase extends TestCase
{
    protected \PDO $pdo;

    protected PdoTransactions $transactions;

    /**
     * @return iterable<string, array{string}>
     */
    public static function implementations(): iterable
    {
        yield 'PDO on SQLite' => ['sqlite'];
    }

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        PdoPrincipalStore::createTable($this->pdo);
        PdoThrottleStore::createTable($this->pdo);
        PdoFlowStore::createTable($this->pdo);
        $this->transactions = new PdoTransactions($this->pdo);
    }
}
