<?php

declare(strict_types=1);

namespace Acme\Storage\Tests;

use Acme\Storage\PdoThrottleStore;
use YorvoID\Testing\Contract\ThrottleStoreContract;
use YorvoID\Throttle\ThrottleStore;

final class PdoThrottleStoreTest extends SqliteTestCase
{
    use ThrottleStoreContract;

    protected function throttleStore(string $implementation): ThrottleStore
    {
        return new PdoThrottleStore($this->pdo);
    }
}
