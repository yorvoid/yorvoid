<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal\Event;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;

/**
 * A principal's status changed, and the change committed.
 *
 * @api
 */
final readonly class PrincipalStatusChanged
{
    public function __construct(
        public Principal $before,
        public Principal $after,
        public Origin $origin,
        public ?PrincipalId $actor,
    ) {
    }
}
