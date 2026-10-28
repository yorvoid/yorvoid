<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session\Event;

use YorvoID\Authn\Session\Session;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\PrincipalId;

/**
 * A session ended, and it committed; $session->endReason says why.
 *
 * @api
 */
final readonly class SessionEnded
{
    public function __construct(
        public Session $session,
        public Origin $origin,
        public ?PrincipalId $actor,
    ) {
    }
}
