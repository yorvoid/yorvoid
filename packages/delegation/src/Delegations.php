<?php

declare(strict_types=1);

namespace YorvoID\Delegation;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Core\Authorization\Actions;
use YorvoID\Core\Authorization\DecidesForRoot;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Id\IdGenerator;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Core\Time;
use YorvoID\Delegation\Change\IssueDelegation;
use YorvoID\Delegation\Change\RevokeDelegation;
use YorvoID\Delegation\Change\RevokeDelegationsOf;

/**
 * Issues and revokes delegations, each through Operations::run(), in a transaction under the realm's
 * lock with its security log entry (D-52). Issuing checks what the decision can't: the actions exist
 * and may be delegated to the delegate, the limits, and for a delegation passed on, its parent's chain
 * now and the new link within it on every dimension. Revoking writes the delegation alone: what was
 * passed on from it stops at its next use, when its chain is checked.
 *
 * @api
 *
 * @experimental
 */
final readonly class Delegations implements OperationHandler
{
    /**
     * @param Actions|\Closure(): Actions                    $actions
     * @param DecidesForRoot|\Closure(): DecidesForRoot|null $roots   What checks a chain's root now, when a delegation is passed on: without it, none is
     */
    public function __construct(
        private DelegationStore $store,
        private PrincipalStore $principals,
        private Actions|\Closure $actions,
        private SecurityLog $log,
        private Transactions $transactions,
        private ClockInterface $clock = new NativeClock(),
        private IdGenerator $ids = new UuidV7Generator(),
        private DelegationLimits $limits = new DelegationLimits(),
        private Purposes $purposes = new Purposes(),
        private DecidesForRoot|\Closure|null $roots = null,
    ) {
    }

    public function operations(): array
    {
        return [
            IssueDelegation::class => $this->issue(...),
            RevokeDelegation::class => $this->revoke(...),
            RevokeDelegationsOf::class => $this->revokeAllOf(...),
        ];
    }

    /**
     * @throws DelegationRefused When it can't be issued as asked
     */
    public function issue(IssueDelegation $command, Permit $permit, Origin $origin): Delegation
    {
        $permit->use($command);
        $delegatorId = $permit->actor->principalId ?? throw new DelegationRefused('no_delegator', 'Only a principal delegates: the operator has no authority of its own to pass on.');

        return $this->transactions->run(function () use ($command, $permit, $origin, $delegatorId): Delegation {
            // The realm first, as every change of authority: a revocation of the parent waits for this,
            // or this for it, and each reads what the other committed (D-52).
            $this->transactions->lockRealm($command->realm);
            $now = Time::stored($this->clock->now());
            $delegator = $this->active($command->realm, $delegatorId, 'delegator');
            $delegate = $this->active($command->realm, $command->delegate, 'delegate');

            if (PrincipalKind::Agent === $delegator->kind) {
                throw new DelegationRefused('agent_delegator', 'An agent doesn\'t pass its authority on.');
            }

            if ($delegate->id->equals($delegator->id)) {
                throw new DelegationRefused('to_oneself', 'A delegation is to someone else.');
            }

            $actions = $this->expand($command->actions, $delegate->kind);
            $redelegable = $this->expand($command->redelegable, $delegate->kind);
            $parent = null === $command->parent ? null : $this->parentOf($command, $delegator, $now);
            $delegation = new Delegation(
                new DelegationId($this->ids->next()),
                $command->realm,
                $delegator->id,
                $delegate->id,
                $parent?->id,
                $parent->root ?? $delegator->id,
                null === $parent ? 0 : $parent->depth + 1,
                $actions,
                array_values(array_unique($command->actions)),
                $command->resources,
                $command->purpose,
                Time::stored($command->notBefore ?? $now),
                Time::stored($command->expiresAt),
                $redelegable,
                $command->depth,
                $command->execution,
                $command->stepUp,
                $permit->decisionId,
                $permit->read->versions['policy'] ?? 0,
                $now,
            );

            $this->withinLimits($delegation, $delegate);

            if (null !== $parent) {
                $outside = $this->attenuation()->inconsistent($delegation, $parent) ?? $this->attenuation()->outside($delegation, $parent);

                if (null !== $outside) {
                    throw new DelegationRefused('outside_parent', \sprintf('It would have %s.', $outside));
                }

                $this->rootHoldsNow($delegation, $origin);
            }

            $this->store->add($delegation);
            $permit->created(new ResourceRef('delegation', (string) $delegation->id));
            $this->log->record($command->realm, 'yorvoid.delegation.issue', Outcome::Success, $origin, $delegator->id, 'delegation', (string) $delegation->id, [
                'delegate' => (string) $delegate->id,
                'parent' => null === $parent ? null : (string) $parent->id,
                'root' => (string) $delegation->root,
                'depth' => $delegation->depth,
                'actions' => \count($actions),
                'expires_at' => $delegation->expiresAt->format(\DATE_ATOM),
            ]);

            return $delegation;
        });
    }

    /**
     * @return bool Whether it was active
     *
     * @throws DelegationRefused When there's no such delegation, or it isn't the actor's to revoke
     */
    public function revoke(RevokeDelegation $command, Permit $permit, Origin $origin): bool
    {
        $permit->use($command);
        $actor = $permit->actor->principalId;

        return $this->transactions->run(function () use ($command, $origin, $actor): bool {
            $this->transactions->lockRealm($command->realm);
            $delegation = $this->store->get($command->realm, $command->delegation) ?? throw new DelegationRefused('unknown_delegation', \sprintf('There is no delegation %s in the realm "%s".', $command->delegation, $command->realm->name));

            // The operator, the delegator, or a delegator above it in its chain.
            if (null !== $actor && !$this->issuedAbove($delegation, $actor)) {
                throw new DelegationRefused('not_yours', 'Only its delegator, or one above it in its chain, revokes a delegation.');
            }

            if (!$delegation->isActive()) {
                return false;
            }

            $revoked = $this->revokeOne($delegation, $actor, $command->why);

            if ($revoked) {
                $this->log->record($command->realm, 'yorvoid.delegation.revoke', Outcome::Success, $origin, $actor, 'delegation', (string) $delegation->id, ['delegate' => (string) $delegation->delegate]);
            }

            return $revoked;
        });
    }

    /**
     * @return int How many were revoked
     */
    public function revokeAllOf(RevokeDelegationsOf $command, Permit $permit, Origin $origin): int
    {
        $permit->use($command);
        $actor = $permit->actor->principalId;

        return $this->transactions->run(function () use ($command, $origin, $actor): int {
            $this->transactions->lockRealm($command->realm);
            $revoked = 0;

            foreach ($this->store->involving($command->realm, $command->principalId) as $delegation) {
                $revoked += $this->revokeOne($delegation, $actor, $command->why) ? 1 : 0;
            }

            $this->log->record($command->realm, 'yorvoid.delegation.revoke_all', Outcome::Success, $origin, $actor, 'principal', (string) $command->principalId, ['revoked' => $revoked]);

            return $revoked;
        });
    }

    private function revokeOne(Delegation $delegation, ?PrincipalId $by, string $why): bool
    {
        return $this->store->revoke($delegation, $delegation->revoked(Time::stored($this->clock->now()), $by, $why));
    }

    /**
     * Whether the principal issued it, or a delegation above it in its chain.
     */
    private function issuedAbove(Delegation $delegation, PrincipalId $principal): bool
    {
        $seen = [];

        for ($link = $delegation; null !== $link && !isset($seen[(string) $link->id]); $link = null === $link->parent ? null : $this->store->get($link->realm, $link->parent)) {
            if ($link->delegator->equals($principal)) {
                return true;
            }

            $seen[(string) $link->id] = true;
        }

        return false;
    }

    /**
     * The root holds every action it passes on now, on each resource it names by ID, or in the whole realm
     * (06 §5.3): a delegation passed on from a root that lost the authority is refused when it's issued,
     * as its every use would be.
     */
    private function rootHoldsNow(Delegation $delegation, Origin $origin): void
    {
        $roots = $this->roots instanceof \Closure ? ($this->roots)() : $this->roots;

        if (!$roots instanceof DecidesForRoot) {
            throw new DelegationRefused('no_root_check', 'Passing a delegation on checks its root now: give Delegations the evaluator (DecidesForRoot).');
        }

        $ids = $delegation->resources->ids();
        $resources = null === $ids ? [null] : array_map(static fn (string $id): ResourceRef => new ResourceRef($delegation->resources->type, $id), $ids->ids);

        foreach ($delegation->actions as $action) {
            $ability = null === $this->actions()->operation($action) ? $action : ($this->actions()->abilityOf($action) ?? $action);

            foreach ($resources as $resource) {
                $truth = $roots->rootHolds($delegation->root, $ability, $delegation->realm, $resource, $origin);

                if (TruthValue::Holds !== $truth->value) {
                    throw new DelegationRefused('root_not_permitted', \sprintf('Its root may not pass it on now: %s', $truth->detail));
                }
            }
        }
    }

    /**
     * The parent the delegator passes on: theirs, and its chain valid now.
     */
    private function parentOf(IssueDelegation $command, Principal $delegator, \DateTimeImmutable $now): Delegation
    {
        \assert(null !== $command->parent);
        $parent = $this->store->get($command->realm, $command->parent) ?? throw new DelegationRefused('unknown_parent', \sprintf('There is no delegation %s to pass on.', $command->parent));

        if (!$parent->delegate->equals($delegator->id)) {
            throw new DelegationRefused('not_yours', 'Only its delegate passes a delegation on.');
        }

        $chain = new Chains($this->store, $this->principals, $this->attenuation(), $this->limits)->check($parent, $now);

        if (true !== $chain->valid) {
            throw new DelegationRefused('parent_chain', \sprintf('Its parent\'s chain doesn\'t hold now: %s.', $chain->reason));
        }

        return $parent;
    }

    /**
     * The actions named, each pattern expanded against the catalogue: every one must exist, and may be
     * delegated to the delegate (06 §5.4). Never dropped quietly: the delegator narrows what they ask.
     *
     * @param list<string> $named
     *
     * @return list<string> Sorted
     */
    private function expand(array $named, PrincipalKind $delegate): array
    {
        $known = [...$this->actions()->operations(), ...$this->actions()->abilities()];
        $expanded = [];

        foreach ($named as $pattern) {
            $matched = str_ends_with($pattern, '.*') ? array_values(array_filter($known, static fn (string $name): bool => str_starts_with($name, substr($pattern, 0, -1)))) : (\in_array($pattern, $known, true) ? [$pattern] : []);

            if ([] === $matched) {
                throw new DelegationRefused('unknown_action', \sprintf('"%s" names no action.', $pattern));
            }

            // A name both an operation and an ability is delegated as both: each must allow it.
            $refused = array_values(array_filter($matched, fn (string $name): bool => false === $this->actions()->operation($name)?->to($delegate) || false === $this->actions()->ability($name)?->to($delegate)));

            if ([] !== $refused) {
                throw new DelegationRefused('non_delegable', \sprintf('"%s" includes what may not be delegated to a principal of the kind %s: %s.', $pattern, $delegate->value, implode(', ', $refused)));
            }

            array_push($expanded, ...$matched);
        }

        $expanded = array_values(array_unique($expanded));
        sort($expanded, \SORT_STRING);

        return $expanded;
    }

    private function withinLimits(Delegation $delegation, Principal $delegate): void
    {
        $longest = $this->limits->longestFor($delegate->kind);
        $why = match (true) {
            [] === $delegation->actions => 'It names no action.',
            \count($delegation->actions) > $this->limits->actions => \sprintf('It names more than %d actions.', $this->limits->actions),
            \count($delegation->resources->ids()->ids ?? []) > $this->limits->ids => \sprintf('It names more than %d resources.', $this->limits->ids),
            $delegation->expiresAt <= $delegation->notBefore => 'It ends before it starts.',
            $delegation->expiresAt > $longest->after($delegation->notBefore) => \sprintf('It lasts longer than %s.', $longest),
            $delegation->remainingDepth < 0 || $delegation->depth + 1 + $delegation->remainingDepth > $this->limits->depth => \sprintf('A chain is %d delegations at most.', $this->limits->depth),
            [] !== array_diff($delegation->redelegable, $delegation->actions) => 'It passes on what it doesn\'t give.',
            [] === $delegation->redelegable && $delegation->remainingDepth > 0 => 'It may be passed on, but names nothing to pass on.',
            default => null,
        };

        if (null !== $why) {
            throw new DelegationRefused('limits', $why);
        }
    }

    private function active(\YorvoID\Core\Realm $realm, PrincipalId $id, string $role): Principal
    {
        $principal = $this->principals->get($realm, $id);

        return null !== $principal && $principal->isActive() ? $principal : throw new DelegationRefused('inactive_'.$role, \sprintf('The %s %s isn\'t an active principal of the realm "%s".', $role, $id, $realm->name));
    }

    private function attenuation(): Attenuation
    {
        return new Attenuation($this->purposes);
    }

    /**
     * The actions, built when first needed: they list the operations, whose catalogue lists this handler.
     */
    private function actions(): Actions
    {
        if ($this->actions instanceof Actions) {
            return $this->actions;
        }

        return ($this->actions)();
    }
}
