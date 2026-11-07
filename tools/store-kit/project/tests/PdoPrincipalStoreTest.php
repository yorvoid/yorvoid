<?php

declare(strict_types=1);

namespace Acme\Storage\Tests;

use Acme\Storage\PdoPrincipalStore;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Testing\Contract\PrincipalStoreContract;

final class PdoPrincipalStoreTest extends SqliteTestCase
{
    use PrincipalStoreContract;

    protected function principalStore(string $implementation): PrincipalStore
    {
        return new PdoPrincipalStore($this->pdo);
    }
}
