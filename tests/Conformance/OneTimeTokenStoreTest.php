<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Authn\Token\OneTimeTokenStore;
use YorvoID\Testing\Contract\OneTimeTokenStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every OneTimeTokenStore keeps the same promises (PLT-025).
 */
final class OneTimeTokenStoreTest extends DatabaseTestCase
{
    use OneTimeTokenStoreContract;

    protected function oneTimeTokenStore(string $implementation): OneTimeTokenStore
    {
        return $this->stackOf($implementation)->tokens;
    }
}
