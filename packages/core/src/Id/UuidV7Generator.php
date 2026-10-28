<?php

declare(strict_types=1);

namespace YorvoID\Core\Id;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\UuidV7;

/**
 * UUIDv7: time-ordered, so new rows land at the end of indexes, and IDs created in one process are
 * strictly increasing even within the same millisecond. The time comes from the injected clock.
 *
 * @api
 */
final readonly class UuidV7Generator implements IdGenerator
{
    public function __construct(
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function next(): UuidV7
    {
        return new UuidV7(UuidV7::generate($this->clock->now()));
    }
}
