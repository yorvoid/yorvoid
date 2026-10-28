<?php

declare(strict_types=1);

namespace YorvoID\Throttle;

/**
 * Where each key's state is kept: one moment per key, in milliseconds since the Unix epoch, when the
 * key will be clear of every attempt counted so far. Keys arrive hashed.
 *
 * @api
 */
interface ThrottleStore
{
    /**
     * When the key will be clear; null if it has no state.
     */
    public function clearAt(string $key): ?int;

    /**
     * Sets when the key will be clear, if it's still $expected (null: if it has no state), as one atomic
     * step: of several requests changing the same key at once, one succeeds.
     *
     * @return bool Whether it did
     */
    public function replace(string $key, ?int $expected, int $clearAt): bool;

    public function remove(string $key): void;

    /**
     * Removes the keys that are clear at the moment.
     *
     * @return int How many
     */
    public function purge(int $now): int;
}
