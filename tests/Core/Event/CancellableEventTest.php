<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Event;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Event\CancellableEvent;

final class CancellableEventTest extends TestCase
{
    public function testCancellingStopsTheOtherListenersAndKeepsTheReason(): void
    {
        $event = new class extends CancellableEvent {};

        self::assertFalse($event->isCancelled());
        self::assertFalse($event->isPropagationStopped());
        self::assertNull($event->reason());

        $event->cancel('Only admins can sign in during maintenance.');

        self::assertTrue($event->isCancelled());
        self::assertTrue($event->isPropagationStopped());
        self::assertSame('Only admins can sign in during maintenance.', $event->reason());
    }
}
