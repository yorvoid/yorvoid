<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\InMemory;

/**
 * An in-memory store that takes part in InMemoryTransactions: its state is saved when a transaction
 * begins and put back if it fails, so in-memory storage keeps the same all-or-nothing contract as a
 * database.
 *
 * @template T The store's state
 *
 * @api
 */
interface InMemoryStore
{
    /**
     * @return T
     */
    public function snapshot(): mixed;

    /**
     * @param T $snapshot
     */
    public function restore(mixed $snapshot): void;
}
