<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Authn\Session\KnownDeviceStore;
use YorvoID\Testing\Contract\KnownDeviceStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every KnownDeviceStore keeps the same promises (PLT-025).
 */
final class KnownDeviceStoreTest extends DatabaseTestCase
{
    use KnownDeviceStoreContract;

    protected function knownDeviceStore(string $implementation): KnownDeviceStore
    {
        return $this->stackOf($implementation)->devices;
    }
}
