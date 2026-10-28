<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal\Event;

use YorvoID\Core\Event\CancellableEvent;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\Identifier;
use YorvoID\Core\Principal\PrincipalId;

/**
 * A principal's email address or username is about to be set, verified or removed, inside the
 * transaction; cancel to refuse. Not dispatched when a principal is created or deleted: see
 * PrincipalCreating and PrincipalStatusChanging.
 *
 * @api
 */
final class IdentifierChanging extends CancellableEvent
{
    /**
     * @param Identifier|null $before Null when the principal had none of this kind
     * @param Identifier|null $after  Null when it's removed
     */
    public function __construct(
        public readonly PrincipalId $principalId,
        public readonly ?Identifier $before,
        public readonly ?Identifier $after,
        public readonly Origin $origin,
        public readonly ?PrincipalId $actor,
    ) {
    }
}
