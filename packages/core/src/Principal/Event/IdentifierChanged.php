<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal\Event;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\Identifier;
use YorvoID\Core\Principal\PrincipalId;

/**
 * A principal's email address or username was set, verified or removed, and the change committed.
 *
 * @api
 */
final readonly class IdentifierChanged
{
    /**
     * @internal YorvoID dispatches it: its listeners act on it, so an application doesn't make one
     */
    public function __construct(
        public PrincipalId $principalId,
        public ?Identifier $before,
        public ?Identifier $after,
        public Origin $origin,
        public ?PrincipalId $actor,
        public Permit $permit,
    ) {
    }
}
