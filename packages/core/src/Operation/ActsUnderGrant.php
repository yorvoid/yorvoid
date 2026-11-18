<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

/**
 * An actor acting under a grant, e.g. an agent (06 §5): the evaluator checks the grant's chain for it,
 * up to its root (M3-2). The shape only, until then.
 *
 * @api
 *
 * @experimental
 */
interface ActsUnderGrant extends Actor
{
    /** The grant it acts under */
    public string $grant { get; }
}
