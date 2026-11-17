<?php

declare(strict_types=1);

namespace App\Tests;

use YorvoID\Authn\StepUp\CoolDownStore;
use YorvoID\Authn\StepUp\Storage\InMemoryCoolDownStore;
use YorvoID\Testing\Contract\CoolDownStoreChecks;

/**
 * An application's own store test: the contract calls the store it tests.
 */
final class MyCoolDownStoreTest
{
    use CoolDownStoreChecks;

    protected function coolDownStore(): CoolDownStore
    {
        return new InMemoryCoolDownStore();
    }
}
