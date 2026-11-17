<?php

declare(strict_types=1);

namespace YorvoID\Fake;

/**
 * A store's implementation, with a method of its own that isn't the store's.
 */
final class FakeImplementation implements FakeStore
{
    public function write(): void
    {
    }

    public function extra(): void
    {
    }
}
