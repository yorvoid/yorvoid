<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session\Event;

use YorvoID\Authn\Session\Session;
use YorvoID\Core\Event\Origin;

/**
 * A session started, and it committed.
 *
 * @api
 */
final readonly class SessionStarted
{
    public function __construct(
        public Session $session,
        public Origin $origin,
    ) {
    }
}
