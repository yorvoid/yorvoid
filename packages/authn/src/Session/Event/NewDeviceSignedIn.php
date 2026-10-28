<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session\Event;

use YorvoID\Authn\Session\Session;
use YorvoID\Core\Event\Origin;

/**
 * A principal signed in from a device it hasn't used before, while it has used others, and it
 * committed (ATN-080): tell them, with "this wasn't me".
 *
 * @api
 */
final readonly class NewDeviceSignedIn
{
    public function __construct(
        public Session $session,
        public Origin $origin,
    ) {
    }
}
