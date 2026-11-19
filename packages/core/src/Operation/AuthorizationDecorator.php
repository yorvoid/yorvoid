<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Authorization\RequiresForbids;
use YorvoID\Core\Event\Origin;

/**
 * An Authorization around another, which it forwards everything to: extend it and override what you
 * add to, usually decide() and decideConsequence(), e.g. to trace or to refuse more. It can narrow the
 * inner decision, returning it or a Decision::deny() or Decision::indeterminate() of its own, but not
 * permit: a Permit comes only from the Evaluator (D-50).
 *
 * ```php
 * final class Traced extends AuthorizationDecorator
 * {
 *     public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
 *     {
 *         $decision = parent::decide($operation, $command, $actor, $origin);
 *         $this->tracer->note($operation->name, $decision->verdict);
 *
 *         return $decision;
 *     }
 * }
 * ```
 *
 * @api
 */
abstract class AuthorizationDecorator implements Authorization
{
    public function __construct(
        protected readonly Authorization $inner,
    ) {
    }

    public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        return $this->inner->decide($operation, $command, $actor, $origin);
    }

    public function decideConsequence(Decision $parent, OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        return $this->inner->decideConsequence($parent, $operation, $command, $actor, $origin);
    }

    public function requireForbids(string $what, RequiresForbids $needing): void
    {
        $this->inner->requireForbids($what, $needing);
    }

    public function checkCatalogue(OperationCatalogue $catalogue): void
    {
        $this->inner->checkCatalogue($catalogue);
    }

    public function decidesOffline(): bool
    {
        return $this->inner->decidesOffline();
    }
}
