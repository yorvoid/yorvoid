<?php

declare(strict_types=1);

namespace Acme\Storage\Tests;

use Acme\Storage\PdoPrincipalStore;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Testing\Contract\TransactionsContract;

final class PdoTransactionsTest extends SqliteTestCase
{
    use TransactionsContract;

    protected function transactions(string $implementation): Transactions
    {
        return $this->transactions;
    }

    protected function principalStore(string $implementation): PrincipalStore
    {
        return new PdoPrincipalStore($this->pdo);
    }
}
