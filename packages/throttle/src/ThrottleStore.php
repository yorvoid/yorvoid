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
     * Counts an attempt, as one atomic step: moves when the key will be clear to the later of that and
     * $now, plus $interval, unless the later of the two is after $latest. Of many requests counting the
     * same key at once, each counts or is refused on what the others left, and none is lost.
     *
     * @param int $now      Milliseconds since the epoch
     * @param int $interval Milliseconds one attempt adds
     * @param int $latest   The latest clear time at which another attempt is allowed
     *
     * @return int|null When the key will be clear now; null if the attempt wasn't allowed, and nothing changed
     */
    public function advance(string $key, int $now, int $interval, int $latest): ?int;

    public function remove(string $key): void;

    /**
     * Removes the keys that are clear at the moment.
     *
     * @return int How many
     */
    public function purge(int $now): int;
}
