<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

/**
 * An actor acting under a delegation, e.g. an agent (06 §5): the evaluator checks the chain of
 * delegations up to its root (M3-2), and the actor may do no more than the root may (D-47). The shape
 * only, until then.
 *
 * @api
 *
 * @experimental
 */
interface ActsUnderDelegation extends Actor
{
    /** The delegation it acts under */
    public string $delegation { get; }
}
