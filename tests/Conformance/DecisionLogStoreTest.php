<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Core\Authorization\DecisionLog\DecisionLogStore;
use YorvoID\Testing\Contract\DecisionLogStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

final class DecisionLogStoreTest extends DatabaseTestCase
{
    use DecisionLogStoreContract;

    protected function decisionLogStore(string $implementation): DecisionLogStore
    {
        return $this->stackOf($implementation)->decisions;
    }
}
