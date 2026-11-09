<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting\Symfony;

use Symfony\Contracts\Service\ResetInterface;
use YorvoID\Core\Storage\InMemory\InMemoryStore;
use YorvoID\Testing\InMemoryStorage;

/**
 * The in-memory stores of the policy under test, emptied for each case: each back to what it held when
 * the container made it, nothing, and every service that keeps something for a request reset.
 *
 * @internal
 */
final class Stores
{
    /** @var list<array{InMemoryStore<mixed>, mixed}> */
    private array $empty = [];

    public function __construct(
        private readonly InMemoryStorage $storage,
        private readonly ResetInterface $resetter,
    ) {
        foreach (get_object_vars($storage) as $store) {
            if ($store instanceof InMemoryStore) {
                $this->empty[] = [$store, $store->snapshot()];
            }
        }
    }

    /**
     * One of InMemoryStorage's stores, by its name there: what the container gives for its interface.
     */
    public static function store(InMemoryStorage $storage, string $name): object
    {
        $store = get_object_vars($storage)[$name] ?? null;

        return \is_object($store) ? $store : throw new \LogicException(\sprintf('InMemoryStorage has no store "%s".', $name));
    }

    /**
     * What the container gives for YorvoID's connection: nothing. Policy tests never use the database.
     *
     * @throws \LogicException Always
     */
    public static function noDatabase(): never
    {
        throw new \LogicException('Policy tests never use the database: something asked for YorvoID\'s connection, so a store isn\'t in memory. Report it, with the service that asked.');
    }

    public function empty(): void
    {
        $this->resetter->reset();

        foreach ($this->empty as [$store, $snapshot]) {
            $store->restore($snapshot);
        }
    }
}
