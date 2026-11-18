<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

/**
 * On a test case of a store contract: its stores are in memory, one process's alone, so they have no
 * concurrency contract to pass. Only for stores that can't be shared between processes.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class InMemoryStores
{
}
