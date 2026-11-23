<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * A principal that owns agents isn't deleted: move them to another owner (ChangeAgentOwner), or delete
 * them, first. The decision refuses it (owns_agents); this is when an agent was added in between.
 *
 * @api
 */
final class OwnsAgents extends \RuntimeException implements YorvoIDException
{
    public function __construct(
        public readonly PrincipalId $owner,
    ) {
        parent::__construct(\sprintf('The principal %s owns agents: move them to another owner, or delete them, first.', $owner));
    }
}
