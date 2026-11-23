<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * A command that takes delegated authority back: its effect takes what the delegations gave, truthfully,
 * but no-escalation doesn't weigh it, since who may revoke is the chain's (its delegator, or one above
 * it), not what the revoker holds now. A delegator whose own role is gone still revokes what they gave:
 * revoking is never held back.
 *
 * @api
 *
 * @experimental
 */
interface RevokesDelegation
{
}
