<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Policy\PolicyStore;
use YorvoID\Testing\Contract\PolicyStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

final class PolicyStoreTest extends DatabaseTestCase
{
    use PolicyStoreContract;

    protected function policyStore(string $implementation): PolicyStore
    {
        return $this->stackOf($implementation)->policy;
    }
}
