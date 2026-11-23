<?php

declare(strict_types=1);

namespace YorvoID\Delegation;

use YorvoID\Core\Authorization\Actions;
use YorvoID\Core\Authorization\DelegatedScope;
use YorvoID\Core\Authorization\Ground;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\ValidatesChains;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalStore;

/**
 * The chain of a delegation an actor acts under, validated at its use (06 §5.3), for core's evaluator:
 * the delegation given to that actor, every link holding now (Chains), and its actions those still
 * delegable to the delegate, as the catalogue says now. A known failure fails; a link missing or
 * inconsistent is unknown.
 *
 * @api
 *
 * @experimental
 */
final readonly class ChainValidator implements ValidatesChains
{
    /**
     * @param Actions|\Closure(): Actions $actions
     */
    public function __construct(
        private DelegationStore $store,
        private PrincipalStore $principals,
        private Actions|\Closure $actions,
        private Purposes $purposes = new Purposes(),
        private DelegationLimits $limits = new DelegationLimits(),
    ) {
    }

    public function validate(DelegationId $leaf, PrincipalId $delegate, Request $request): DelegatedScope|Truth
    {
        $delegation = $this->store->get($request->realm, $leaf);

        if (null === $delegation) {
            return Truth::unknown(IndeterminateReason::MissingData, \sprintf('There is no delegation %s in the realm "%s".', $leaf, $request->realm->name))->grounded(Ground::authority('unknown_delegation'));
        }

        if (!$delegation->delegate->equals($delegate)) {
            return Truth::fails('The delegation wasn\'t given to this actor.')->grounded(Ground::authority('not_the_delegate'));
        }

        $chain = new Chains($this->store, $this->principals, new Attenuation($this->purposes), $this->limits)->check($delegation, $request->at);

        if (false === $chain->valid) {
            return Truth::fails(\sprintf('The delegation\'s chain doesn\'t hold: %s.', $chain->reason))->grounded(Ground::authority('delegation_invalid'));
        }

        if (null === $chain->valid) {
            return Truth::unknown(IndeterminateReason::ConflictingRecords, \sprintf('The delegation\'s chain can\'t be established: %s.', $chain->reason))->grounded(Ground::authority('delegation_unknown'));
        }

        $kind = $this->principals->get($request->realm, $delegate)?->kind;

        if (null === $kind) {
            return Truth::unknown(IndeterminateReason::MissingData, 'The delegate isn\'t on record.');
        }

        // What may be delegated is decided now: an action no longer delegable to the delegate is dropped.
        $actions = $this->actions();
        $still = array_values(array_filter($delegation->actions, static fn (string $name): bool => false !== $actions->operation($name)?->to($kind) && false !== $actions->ability($name)?->to($kind) && (null !== $actions->operation($name) || null !== $actions->ability($name))));

        return new DelegatedScope(
            $delegation->id,
            $delegation->delegate,
            $delegation->root,
            $still,
            $delegation->resources,
            null === $delegation->purpose ? null : $this->purposes->below($delegation->purpose),
            $delegation->execution,
            $delegation->stepUp,
            array_map(static fn (Delegation $link): DelegationId => $link->id, $chain->links),
        );
    }

    private function actions(): Actions
    {
        if ($this->actions instanceof Actions) {
            return $this->actions;
        }

        return ($this->actions)();
    }
}
