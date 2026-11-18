<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Authn\Setup\SetupStore;
use YorvoID\Testing\Contract\ConcurrencyTestedIn;
use YorvoID\Testing\Contract\SetupStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every SetupStore keeps the same promises (PLT-025).
 */
#[ConcurrencyTestedIn(StoreConcurrencyTest::class)]
final class SetupStoreTest extends DatabaseTestCase
{
    use SetupStoreContract;

    protected function setupStore(string $implementation): SetupStore
    {
        return $this->stackOf($implementation)->setup;
    }
}
