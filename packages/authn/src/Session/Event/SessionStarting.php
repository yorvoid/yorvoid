<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session\Event;

use YorvoID\Authn\Session\Session;
use YorvoID\Core\Event\CancellableEvent;
use YorvoID\Core\Event\Origin;

/**
 * A session is about to start, inside the transaction; cancel to refuse the sign-in, e.g. outside
 * office hours.
 *
 * @api
 */
final class SessionStarting extends CancellableEvent
{
    public function __construct(
        public readonly Session $session,
        public readonly Origin $origin,
    ) {
    }
}
