<?php

declare(strict_types=1);

namespace Acme\Storage\Tests;

use Acme\Storage\PdoFlowStore;
use YorvoID\Flows\FlowStore;
use YorvoID\Testing\Contract\FlowStoreContract;

final class PdoFlowStoreTest extends SqliteTestCase
{
    use FlowStoreContract;

    protected function flowStore(string $implementation): FlowStore
    {
        return new PdoFlowStore($this->pdo);
    }
}
