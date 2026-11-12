<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Authorization\Truth;

/**
 * No self-service: nobody changes their own account, whatever their role; someone else, or the operator,
 * does. The default without a self-service policy. A choice, not a gap, so a refusal, not "couldn't decide".
 * Signing out isn't self-service: anyone ends their own sessions, e.g. one they think someone else has.
 *
 * @api
 */
final readonly class NoSelfService implements SelfServicePolicy
{
    /** Signing out, here or everywhere */
    private const array SIGNING_OUT = ['yorvoid.session.end', 'yorvoid.session.end_all'];

    public function decide(OperationDescription $operation, Command $command, Actor $actor): Truth
    {
        if (\in_array($operation->name, self::SIGNING_OUT, true)) {
            return Truth::holds('Signing out isn\'t self-service.');
        }

        return Truth::fails('This application has no self-service: someone else changes one\'s own account.');
    }
}
