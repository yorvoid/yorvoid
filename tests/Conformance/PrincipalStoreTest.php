<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Testing\Contract\PrincipalStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every PrincipalStore keeps the same promises (PLT-025).
 */
final class PrincipalStoreTest extends DatabaseTestCase
{
    use PrincipalStoreContract;

    protected function principalStore(string $implementation): PrincipalStore
    {
        return $this->stackOf($implementation)->principals;
    }
}
