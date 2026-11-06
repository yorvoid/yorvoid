<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

/**
 * Whether an actor holds an ability on a command's subject, for the operations authorized by an ability,
 * until YorvoID's own evaluator (0.3) decides them: the Symfony bundle asks Symfony's security
 * (`isGranted()`), and an application without it implements this.
 *
 * @api
 */
interface AbilityPolicy
{
    /**
     * @param Command<mixed> $command
     *
     * @return bool|null Null when it can't tell, which never permits
     */
    public function holds(Actor $actor, string $ability, Command $command): ?bool;
}
