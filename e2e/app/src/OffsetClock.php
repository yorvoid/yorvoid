<?php

declare(strict_types=1);

namespace YorvoID\E2e;

use Psr\Clock\ClockInterface;

/**
 * The test app's clock: the real time, moved on by what /_e2e/clock added, so a browser test (or the
 * usability test's moderator) can let a sign-in grow old without waiting for it.
 */
final readonly class OffsetClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable()->modify(\sprintf('+%d seconds', self::offset()));
    }

    public static function advance(int $seconds): void
    {
        file_put_contents(self::file(), (string) (self::offset() + $seconds));
    }

    public static function reset(): void
    {
        @unlink(self::file());
    }

    private static function offset(): int
    {
        return is_file(self::file()) ? (int) file_get_contents(self::file()) : 0;
    }

    private static function file(): string
    {
        return Kernel::data().'/clock.txt';
    }
}
