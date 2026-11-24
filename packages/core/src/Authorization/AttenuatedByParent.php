<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Operation\DelegationId;

/**
 * A command that passes on authority its actor holds by a delegation, within that delegation: what it
 * gives is held by the parent, which its handler checks it's within, not by the actor's own roles. So
 * no-escalation doesn't weigh it, when, and only when, it names a parent. Its effect is still declared,
 * so step-up and approvals see what it gives.
 *
 * @api
 *
 * @experimental
 */
interface AttenuatedByParent
{
    /**
     * The delegation it's within, if any: null for one that gives the actor's own authority, which
     * no-escalation weighs as any other.
     */
    public function attenuatedBy(): ?DelegationId;
}
