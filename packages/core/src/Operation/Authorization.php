<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Event\Origin;

/**
 * Decides whether an actor may run an operation: {@see Operations::run()} asks it before every
 * operation, and only a Permit lets the operation run.
 *
 * @api
 */
interface Authorization
{
    /**
     * @param Command<mixed> $command
     */
    public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision;
}
