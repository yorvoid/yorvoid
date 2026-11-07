<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Authorization\Truth;

/**
 * What someone running an operation on their own account must also show, e.g. that they proved who they
 * are recently: asked by the evaluator's self-service permit once the operation concerns the actor. The
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
     * @return Truth Holds when the actor may; fails with the remedy when evidence is missing, e.g. a step-up
     */
    public function decide(OperationDescription $operation, SelfOperation $command, Actor $actor): Truth;
}
