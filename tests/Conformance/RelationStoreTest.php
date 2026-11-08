<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Relations\RelationStore;
use YorvoID\Relations\Storage\DbalRelationStore;
use YorvoID\Testing\Contract\RelationStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

final class RelationStoreTest extends DatabaseTestCase
{
    use RelationStoreContract;

    protected function relationStore(string $implementation): RelationStore
    {
        return $this->stackOf($implementation)->relations;
    }

    protected function sameRelationStore(string $implementation, RelationStore $store): RelationStore
    {
        return 'memory' === $implementation ? $store : new DbalRelationStore($this->database($implementation));
    }
}
