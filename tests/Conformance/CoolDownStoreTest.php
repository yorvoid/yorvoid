<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Authn\StepUp\CoolDownStore;
use YorvoID\Testing\Contract\CoolDownStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every CoolDownStore keeps the same promises (PLT-025).
 */
final class CoolDownStoreTest extends DatabaseTestCase
{
    use CoolDownStoreContract;

    protected function coolDownStore(string $implementation): CoolDownStore
    {
        return $this->stackOf($implementation)->coolDowns;
    }
}
