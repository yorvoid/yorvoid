<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Principal\PrincipalId;

/**
 * What a valid chain of delegations lets its delegate do, now (06 §5.3): its leaf's actions, still
 * delegable to the delegate, on its leaf's resources, for its purposes, inside its window, for its task,
 * with the proof it asks; and whose authority it is. The evaluator matches a request against it, and
 * decides the same request for the root: the actor never does more than the root may now.
 *
 * @api
 *
 * @experimental
 */
final readonly class DelegatedScope
{
    /**
     * @param list<string>       $actions   The leaf's, still delegable to the delegate
     * @param list<string>|null  $purposes  The leaf's purpose and those below it; null when it has none
     * @param string|null        $execution The task it's bound to, if any
     * @param list<DelegationId> $links     The chain, the leaf first, for the record (I2)
     */
    public function __construct(
        public DelegationId $leaf,
        public PrincipalId $delegate,
        public PrincipalId $root,
        public array $actions,
        public Selector $resources,
        public ?array $purposes,
        public ?string $execution,
        public ?StepUpRemedy $stepUp,
        public array $links,
    ) {
    }
}
