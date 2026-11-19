<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Authorization\RequiresForbids;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;

/**
 * Decides whether an actor may run an operation: {@see Operations::run()} asks it before every
 * operation and each of its consequences, and only a Permit lets either run. This is all the decision
 * point asks of it (D-50), so it can be decorated: extend {@see AuthorizationDecorator}, which keeps
 * every check. A decorator can narrow a decision but never permit: only the Evaluator makes a Permit.
 *
 * @api
 */
interface Authorization
{
    /**
     * @param Command<mixed> $command
     */
    public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision;

    /**
     * A consequence of a permitted operation, which it names in #[Operation(causes: [...])]: the parent's
     * decision holds unless this refuses it, e.g. a forbid that applies to consequences, or the actor
     * no longer proven.
     *
     * @param Decision       $parent  The permitted operation's decision, or its consequence's
     * @param Command<mixed> $command
     */
    public function decideConsequence(Decision $parent, OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision;

    /**
     * @throws IntegratorError When the forbids the handler needs aren't in the policy, or this can't check them
     */
    public function requireForbids(string $what, RequiresForbids $needing): void;

    /**
     * @throws IntegratorError When the policy can't decide one of the operations, e.g. one whose ability isn't declared
     */
    public function checkCatalogue(OperationCatalogue $catalogue): void;

    /**
     * Whether it can permit offline, while a store is unreachable (P1′).
     */
    public function decidesOffline(): bool;
}
