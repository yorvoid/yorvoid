<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Policy\Attribute\AttributeStore;
use YorvoID\Testing\Contract\AttributeStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

final class AttributeStoreTest extends DatabaseTestCase
{
    use AttributeStoreContract;

    protected function attributeStore(string $implementation): AttributeStore
    {
        return $this->stackOf($implementation)->attributes;
    }
}
