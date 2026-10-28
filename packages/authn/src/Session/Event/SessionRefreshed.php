<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session\Event;

use YorvoID\Authn\Session\Session;
use YorvoID\Core\Event\Origin;

/**
 * A session got a new token, e.g. when its principal stepped up (SES-002): the old token no longer works.
 *
 * @api
 */
final readonly class SessionRefreshed
{
    public function __construct(
        public Session $session,
        public Origin $origin,
    ) {
    }
}
