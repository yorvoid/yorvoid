<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Testing\Contract\ThrottleStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Throttle\ThrottleStore;

/**
 * Every ThrottleStore keeps the same promises (PLT-025).
 */
final class ThrottleStoreTest extends DatabaseTestCase
{
    use ThrottleStoreContract;

    protected function throttleStore(string $implementation): ThrottleStore
    {
        return $this->stackOf($implementation)->throttle;
    }
}
