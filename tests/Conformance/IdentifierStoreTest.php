<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Core\Principal\IdentifierStore;
use YorvoID\Testing\Contract\IdentifierStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every IdentifierStore keeps the same promises (PLT-025).
 */
final class IdentifierStoreTest extends DatabaseTestCase
{
    use IdentifierStoreContract;

    protected function identifierStore(string $implementation): IdentifierStore
    {
        return $this->stackOf($implementation)->identifiers;
    }
}
