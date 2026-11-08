<?php

declare(strict_types=1);

namespace YorvoID\Tests\Testing;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Storage\InMemory\InMemoryStore;
use YorvoID\Testing\InMemoryStorage;

/**
 * Every in-memory store, registered: a failed unit of work undoes what it did in each (BND-06).
 */
final class InMemoryStorageTest extends TestCase
{
    public function testAFailedUnitOfWorkUndoesItInEveryStore(): void
    {
        $memory = new InMemoryStorage();
        $stores = array_filter(get_object_vars($memory), static fn (mixed $store): bool => $store instanceof InMemoryStore);
        self::assertCount(15, $stores, 'The throttle\'s counts aren\'t undone: an attempt counts even when its work is.');
        $before = array_map(static fn (InMemoryStore $store): mixed => $store->snapshot(), $stores);

        try {
            $memory->transactions->run(static function () use ($stores): never {
                // Each store's content replaced, as a unit of work's writes would.
                foreach ($stores as $store) {
                    $store->restore(['changed']);
                }

                throw new \RuntimeException('The unit of work failed.');
            });
        } catch (\RuntimeException) {
        }

        foreach ($stores as $name => $store) {
            self::assertSame($before[$name], $store->snapshot(), \sprintf('The %s store kept half of a failed change.', $name));
        }
    }
}
