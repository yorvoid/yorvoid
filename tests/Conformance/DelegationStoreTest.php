<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Delegation\DelegationStore;
use YorvoID\Testing\Contract\DelegationStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every DelegationStore keeps the same promises.
 */
final class DelegationStoreTest extends DatabaseTestCase
{
    use DelegationStoreContract;

    protected function delegationStore(string $implementation): DelegationStore
    {
        return $this->stackOf($implementation)->delegations;
    }
}
