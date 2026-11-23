<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

/**
 * An actor acting under a delegation, e.g. an agent (06 §5): the evaluator checks the chain of
 * delegations up to its root, and the actor may do no more than the root may (D-47). Core's own
 * UnderDelegation is the only one the evaluator decides; an actor class of yours that claims to act under
 * one is refused, whatever proves it.
 *
 * @api
 *
 * @experimental
 */
interface ActsUnderDelegation extends Actor
{
    /** The delegation it acts under */
    public DelegationId $delegation { get; }
}
