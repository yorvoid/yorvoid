<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Core\Authorization\VersionStore;
use YorvoID\Testing\Contract\VersionStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every VersionStore keeps the same promises.
 */
final class VersionStoreTest extends DatabaseTestCase
{
    use VersionStoreContract;

    protected function versionStore(string $implementation): VersionStore
    {
        return $this->stackOf($implementation)->versions;
    }
}
