<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Storage\Fixtures;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A pool that's down: every call throws, or only saving does.
 */
final class FailingPool implements CacheItemPoolInterface
{
    public int $calls = 0;

    private ArrayAdapter $pool;

    public function __construct(
        private readonly bool $failSave = false,
    ) {
        $this->pool = new ArrayAdapter();
    }

    public function getItem(string $key): CacheItemInterface
    {
        ++$this->calls;

        if (!$this->failSave) {
            throw new \RuntimeException('The pool is down.');
        }

        return $this->pool->getItem($key);
    }

    /**
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        throw new \LogicException('Not used.');
    }

    public function hasItem(string $key): bool
    {
        throw new \LogicException('Not used.');
    }

    public function clear(): bool
    {
        throw new \LogicException('Not used.');
    }

    public function deleteItem(string $key): bool
    {
        throw new \LogicException('Not used.');
    }

    public function deleteItems(array $keys): bool
    {
        throw new \LogicException('Not used.');
    }

    public function save(CacheItemInterface $item): bool
    {
        throw new \RuntimeException('The pool is full.');
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        throw new \LogicException('Not used.');
    }

    public function commit(): bool
    {
        throw new \LogicException('Not used.');
    }
}
