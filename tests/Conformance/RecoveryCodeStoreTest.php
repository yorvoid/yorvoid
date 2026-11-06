<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Authn\Recovery\RecoveryCodeStore;
use YorvoID\Testing\Contract\RecoveryCodeStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every RecoveryCodeStore keeps the same promises (PLT-025).
 */
final class RecoveryCodeStoreTest extends DatabaseTestCase
{
    use RecoveryCodeStoreContract;

    protected function recoveryCodeStore(string $implementation): RecoveryCodeStore
    {
        return $this->stackOf($implementation)->recoveryCodes;
    }
}
