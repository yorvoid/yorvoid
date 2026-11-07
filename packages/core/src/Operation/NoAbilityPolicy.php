<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

/**
 * Can't tell who holds an ability: an operation authorized by one is Indeterminate unless another way
 * permits it, and never runs on the ability. Without any ability policy, the evaluator has no ability
 * permit at all, and such an operation is denied: no permit applies.
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
