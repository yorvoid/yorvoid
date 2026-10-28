<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal\Event;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\Identifier;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;

/**
 * A principal was created and committed.
 *
 * @api
 */
final readonly class PrincipalCreated
{
    /**
     * @param list<Identifier> $identifiers
     */
    public function __construct(
        public Principal $principal,
        public array $identifiers,
        public Origin $origin,
        public ?PrincipalId $actor,
    ) {
    }
}
