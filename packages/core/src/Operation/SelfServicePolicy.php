<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

/**
 * What someone running an operation on their own account must also show, e.g. that they proved who they
 * are recently: asked by {@see BuiltInAuthorization} once the operation concerns the actor. The
 * authentication package's StepUpSelfService is the one to use; without one, nobody runs operations on
 * their own account.
 *
 * @api
 */
interface SelfServicePolicy
{
    /**
     * @param SelfOperation<mixed> $command
     *
     * @throws \Throwable A remedy the person can act on, e.g. a step-up, until decisions carry remedies
     */
    public function decide(OperationDescription $operation, SelfOperation $command, Actor $actor): Decision;
}
