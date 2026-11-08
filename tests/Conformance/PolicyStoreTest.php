<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\Storage\DbalPolicyStore;
use YorvoID\Testing\Contract\PolicyStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

final class PolicyStoreTest extends DatabaseTestCase
{
    use PolicyStoreContract;

    protected function policyStore(string $implementation): PolicyStore
    {
        return $this->stackOf($implementation)->policy;
    }

    protected function samePolicyStore(string $implementation, PolicyStore $store): PolicyStore
    {
        return 'memory' === $implementation ? $store : new DbalPolicyStore($this->database($implementation));
    }
}
