<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal\Event;

use YorvoID\Core\Event\CancellableEvent;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\Identifier;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;

/**
 * A principal is about to be created, inside the transaction; cancel to refuse.
 *
 * @api
 */
final class PrincipalCreating extends CancellableEvent
{
    /**
     * @param list<Identifier> $identifiers
     */
    public function __construct(
        public readonly Principal $principal,
        public readonly array $identifiers,
        public readonly Origin $origin,
        public readonly ?PrincipalId $actor,
    ) {
    }
}
