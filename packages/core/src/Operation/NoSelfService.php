<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Authorization\Truth;

/**
 * No self-service: nobody changes their own account, whatever their role; someone else, or the operator,
 * does. The default without a self-service policy. A choice, not a gap, so a refusal, not "couldn't decide".
 *
 * @api
 */
final readonly class NoSelfService implements SelfServicePolicy
{
    public function decide(OperationDescription $operation, Command $command, Actor $actor): Truth
    {
        return Truth::fails('This application has no self-service: someone else changes one\'s own account.');
    }
}
