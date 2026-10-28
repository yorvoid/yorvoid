<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Id;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use YorvoID\Core\Id\UuidV7Generator;

final class UuidV7GeneratorTest extends TestCase
{
    public function testIdsCarryTheClocksTime(): void
    {
        $generator = new UuidV7Generator(new MockClock('2026-10-27 18:00:00.123 UTC'));

        self::assertSame('2026-10-27 18:00:00.123', $generator->next()->getDateTime()->format('Y-m-d H:i:s.v'));
    }

    public function testIdsAreStrictlyIncreasingWithinTheSameMillisecond(): void
    {
        $generator = new UuidV7Generator(new MockClock('2026-10-27 18:00:00 UTC'));
        $previous = (string) $generator->next();

        for ($i = 0; $i < 1_000; ++$i) {
            $id = (string) $generator->next();
            self::assertGreaterThan($previous, $id);
            $previous = $id;
        }
    }

    public function testUsesTheSystemClockByDefault(): void
    {
        $before = new \DateTimeImmutable();
        $time = new UuidV7Generator()->next()->getDateTime();

        self::assertGreaterThanOrEqual($before->getTimestamp(), $time->getTimestamp());
        self::assertLessThan($before->getTimestamp() + 5, $time->getTimestamp());
    }
}
