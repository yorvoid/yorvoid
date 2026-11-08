<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use YorvoID\Core\Storage\Installation;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\Storage\CachedPolicyStore;
use YorvoID\Policy\Storage\DbalPolicyStore;
use YorvoID\Testing\Contract\PolicyStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * The cached policy store keeps the contract: over every store, with a pool that servers share.
 */
final class CachedPolicyStoreTest extends DatabaseTestCase
{
    use PolicyStoreContract;

    private ?ArrayAdapter $pool = null;

    protected function policyStore(string $implementation): PolicyStore
    {
        return new CachedPolicyStore($this->stackOf($implementation)->policy, $this->cache());
    }

    protected function samePolicyStore(string $implementation, PolicyStore $store): PolicyStore
    {
        // Another server: its own connection and memo, the same pool.
        return new CachedPolicyStore('memory' === $implementation ? $this->stackOf($implementation)->policy : new DbalPolicyStore($this->database($implementation)), $this->cache());
    }

    private function cache(): VersionedCache
    {
        return new VersionedCache($this->pool ??= new ArrayAdapter(), Installation::named('test'));
    }
}
