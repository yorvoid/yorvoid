<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session\Event;

use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\Session;
use YorvoID\Core\Event\Origin;

/**
 * A session's principal proved who they are again (SES-011), and it committed. The session has a new
 * token; the old one no longer works.
 *
 * @api
 */
final readonly class SessionSteppedUp
{
    public function __construct(
        public Session $session,
        public AuthenticationFactor $factor,
        public Origin $origin,
    ) {
    }
}
