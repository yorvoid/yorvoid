<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

/**
 * Can't tell who holds an ability, so operations authorized by one are Indeterminate, and never run,
 * except for the host's operator where they allow it. The default without a policy.
 *
 * @api
 */
final readonly class NoAbilityPolicy implements AbilityPolicy
{
    public function holds(Actor $actor, string $ability, Command $command): ?bool
    {
        return null;
    }
}
