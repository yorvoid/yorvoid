<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

use Psr\Clock\ClockInterface;

/**
 * @internal
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
