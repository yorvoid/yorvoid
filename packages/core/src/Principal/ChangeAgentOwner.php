<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * Moves an agent to another owner, e.g. before its owner is deleted: an owner of agents can't be.
 *
 * @implements Command<Principal>
 *
 * @api
 */
#[Operation('yorvoid.principal.change_owner', 'Move an agent to another owner', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: 'yorvoid.principals.manage')]
final readonly class ChangeAgentOwner implements Command, ConcernsPrincipal
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $agent,
        public PrincipalId $owner,
    ) {
    }

    public function principal(): PrincipalId
    {
        return $this->agent;
    }
}
