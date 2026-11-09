<?php

declare(strict_types=1);

namespace YorvoID\Testing;

use YorvoID\Core\Clock\Elapsed;
use YorvoID\Core\Clock\Instant;
use YorvoID\Core\Duration;

/**
 * Elapsed time a test moves, apart from the wall clock (a MockClock): so a test can set the wall clock
 * back or forward while the elapsed time runs on, and reboot the machine.
 *
 * @api
 */
final class MockElapsed implements Elapsed
{
    private int $nanoseconds = 0;

    private int $boot = 1;

    public function mark(): Instant
    {
        return new Instant('boot-'.$this->boot, $this->nanoseconds);
    }

    public function since(Instant $mark): ?int
    {
        if ('boot-'.$this->boot !== $mark->clock || $mark->nanoseconds > $this->nanoseconds) {
            return null;
        }

        return $this->nanoseconds - $mark->nanoseconds;
    }

    public function advance(Duration $by): void
    {
        $this->nanoseconds += $by->seconds * 1_000_000_000;
    }

    /**
     * A new boot: its clock starts again, and marks of the old one can't be measured from.
     */
    public function reboot(): void
    {
        ++$this->boot;
        $this->nanoseconds = 0;
    }
}
