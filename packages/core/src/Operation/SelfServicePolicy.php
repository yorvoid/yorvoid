<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Authorization\Truth;

/**
 * What someone running an operation on their own account must also show, e.g. that they proved who they
 * are recently: asked by the evaluator once the operation concerns the actor, whatever permits it (but
 * the operator), a SelfOperation that concerns them or a command about their own principal
 * (ConcernsPrincipal). The authentication package's StepUpSelfService is the one to use; without one,
 * nobody runs operations on their own account.
 *
 * @api
 */
interface SelfServicePolicy
{
    /**
     * @param Command<mixed> $command A SelfOperation, or a command about the actor's own principal
     *
     * @return Truth Holds when the actor may; fails with the remedy when evidence is missing, e.g. a step-up
     */
    public function decide(OperationDescription $operation, Command $command, Actor $actor): Truth;
}
