<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal\Event;

use YorvoID\Core\Event\CancellableEvent;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;

/**
 * A principal's status is about to change, inside the transaction: activated, suspended or deleted. Cancel
 * to refuse; or change the application's own data with it, e.g. remove a deleted person's profile. What
 * the change causes in YorvoID runs on a consequence of its permit, e.g. ending the principal's sessions.
 *
 * @api
 */
final class PrincipalStatusChanging extends CancellableEvent
{
    public function __construct(
        public readonly Principal $before,
        public readonly Principal $after,
        public readonly Origin $origin,
        public readonly ?PrincipalId $actor,
        public readonly Permit $permit,
    ) {
    }
}
