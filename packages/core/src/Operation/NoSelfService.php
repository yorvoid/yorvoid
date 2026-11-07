<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

/**
 * Can't tell what someone must show to change their own account, so nobody may: the default without a
 * self-service policy.
 *
 * @api
 */
final readonly class NoSelfService implements SelfServicePolicy
{
    public function decide(OperationDescription $operation, SelfOperation $command, Actor $actor): Decision
    {
        return Decision::indeterminate(\sprintf('No self-service policy says what "%s" needs.', $operation->name));
    }
}
