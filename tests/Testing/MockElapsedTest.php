<?php

declare(strict_types=1);

namespace YorvoID\Tests\Testing;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Clock\Instant;
use YorvoID\Core\Duration;
use YorvoID\Testing\MockElapsed;

final class MockElapsedTest extends TestCase
{
    public function testMovesByHandAndReboots(): void
    {
        $elapsed = new MockElapsed();
        $first = $elapsed->mark();

        self::assertEquals(new Instant('boot-1', 0), $first);
        $elapsed->advance(Duration::minutes(2));
        self::assertSame(120_000_000_000, $elapsed->since($first));
        self::assertNull($elapsed->since(new Instant('boot-1', 120_000_000_001)), 'Ahead of now.');

        $elapsed->reboot();
        self::assertEquals(new Instant('boot-2', 0), $elapsed->mark());
        self::assertNull($elapsed->since($first), 'The old boot\'s.');
        $elapsed->reboot();
        self::assertSame('boot-3', $elapsed->mark()->clock);
    }
}
