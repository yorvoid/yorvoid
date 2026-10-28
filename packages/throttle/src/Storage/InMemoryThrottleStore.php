<?php

declare(strict_types=1);

namespace YorvoID\Throttle\Storage;

use YorvoID\Throttle\ThrottleStore;

/**
 * Throttling state in memory, for tests and single-process tools. It's not registered with the
 * in-memory transactions: like the database store's, its counts survive a rollback.
 *
 * @api
 */
final class InMemoryThrottleStore implements ThrottleStore
{
    /** @var array<string, int> By key: when it will be clear */
    private array $keys = [];

    public function clearAt(string $key): ?int
    {
        return $this->keys[$key] ?? null;
    }

    public function replace(string $key, ?int $expected, int $clearAt): bool
    {
        if (($this->keys[$key] ?? null) !== $expected) {
            return false;
        }

        $this->keys[$key] = $clearAt;

        return true;
    }

    public function remove(string $key): void
    {
        unset($this->keys[$key]);
    }

    public function purge(int $now): int
    {
        $count = \count($this->keys);
        $this->keys = array_filter($this->keys, static fn (int $clearAt): bool => $clearAt > $now);

        return $count - \count($this->keys);
    }
}
