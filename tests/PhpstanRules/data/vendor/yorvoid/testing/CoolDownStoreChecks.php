<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use YorvoID\Authn\StepUp\CoolDownStore;

/**
 * Stands for a store contract of yorvoid/testing, installed in vendor/yorvoid: it calls the store it
 * tests.
 */
trait CoolDownStoreChecks
{
    abstract protected function coolDownStore(): CoolDownStore;

    public function testPurges(): void
    {
        $this->coolDownStore()->purge(new \DateTimeImmutable());
    }
}
