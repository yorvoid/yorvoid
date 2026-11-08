<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use YorvoID\Core\Storage\Installation;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\Storage\CachedRelationStore;
use YorvoID\Relations\Storage\DbalRelationStore;
use YorvoID\Testing\Contract\RelationStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * The cached relation store keeps the contract: over every store, with a pool that servers share.
 */
final class CachedRelationStoreTest extends DatabaseTestCase
{
    use RelationStoreContract;

    private ?ArrayAdapter $pool = null;

    protected function relationStore(string $implementation): RelationStore
    {
        return new CachedRelationStore($this->stackOf($implementation)->relations, $this->cache());
    }

    protected function sameRelationStore(string $implementation, RelationStore $store): RelationStore
    {
        // Another server: its own connection and memo, the same pool.
        return new CachedRelationStore('memory' === $implementation ? $this->stackOf($implementation)->relations : new DbalRelationStore($this->database($implementation)), $this->cache());
    }

    private function cache(): VersionedCache
    {
        return new VersionedCache($this->pool ??= new ArrayAdapter(), Installation::named('test'));
    }
}
