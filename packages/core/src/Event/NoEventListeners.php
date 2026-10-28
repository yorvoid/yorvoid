<?php

declare(strict_types=1);

namespace YorvoID\Core\Event;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * For applications without an event dispatcher: events go nowhere.
 *
 * @api
 */
final class NoEventListeners implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        return $event;
    }
}
