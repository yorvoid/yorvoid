<?php

declare(strict_types=1);

namespace YorvoID\Delegation;

use YorvoID\Core\Authorization\Actions;
use YorvoID\Core\Authorization\DeclaresEffects;
use YorvoID\Core\Authorization\Effect;
use YorvoID\Core\Authorization\EffectPart;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Selector;
use YorvoID\Core\Authorization\Selector\RelatedTo;
use YorvoID\Delegation\Change\IssueDelegation;
use YorvoID\Delegation\Change\RevokeDelegation;
use YorvoID\Delegation\Change\RevokeDelegationsOf;

/**
 * What the delegation commands give and take (D-51). Issuing gives its actions: on each resource it
 * names by ID, or in the whole realm, so no-escalation weighs whether a root delegator holds them there
 * (a delegation passed on is within its parent instead, AttenuatedByParent), and step-up asks a proof
 * for a strict one. Revoking takes what the delegations gave, from their delegates; no-escalation doesn't
 * weigh it (RevokesDelegation), and taking never waits for a proof.
 *
 * @api
 *
 * @experimental
 */
final readonly class DelegationEffects implements DeclaresEffects
{
    public function __construct(
        private Actions|\Closure $actions,
        private ?DelegationStore $store = null,
    ) {
    }

    public function declares(): array
    {
        return [IssueDelegation::class, RevokeDelegation::class, RevokeDelegationsOf::class];
    }

    public function effectOf(Request $request): Effect
    {
        $command = $request->command;

        if ($command instanceof IssueDelegation) {
            return new Effect(gives: $this->given($command, $request));
        }

        if (null === $this->store) {
            throw new \LogicException('What a revocation takes needs the delegation store: give DelegationEffects one.');
        }

        $revoked = match (true) {
            $command instanceof RevokeDelegation => array_filter([$this->store->get($command->realm, $command->delegation)]),
            $command instanceof RevokeDelegationsOf => $this->store->involving($command->realm, $command->principalId),
            default => [],
        };
        $takes = [];

        foreach ($revoked as $delegation) {
            if ($delegation->isActive()) {
                array_push($takes, ...$this->parts($delegation->actions, $delegation->resources));
            }
        }

        return new Effect(takes: $takes);
    }

    /**
     * What issuing gives. A root delegation of the resources the delegator has a relation to says so, for
     * no-escalation to verify against the relations' definitions; the decision at each use checks each
     * resource. Step-up sees every part.
     *
     * @return list<EffectPart>
     */
    private function given(IssueDelegation $command, Request $request): array
    {
        $parts = $this->parts($command->actions, $command->resources);

        if (null !== $command->parent || null !== $command->resources->ids()) {
            return $parts;
        }

        $relations = array_values(array_map(static fn (RelatedTo $atom): string => $atom->relation, array_filter($command->resources->atoms, static fn (Selector\Atom $atom): bool => $atom instanceof RelatedTo && 'principal' === $atom->subject->type)));
        $subjects = array_values(array_unique(array_map(static fn (RelatedTo $atom): string => (string) $atom->subject, array_filter($command->resources->atoms, static fn (Selector\Atom $atom): bool => $atom instanceof RelatedTo && 'principal' === $atom->subject->type))));

        if ([] === $relations || 1 !== \count($subjects)) {
            return $parts;
        }

        $subject = array_values(array_filter($command->resources->atoms, static fn (Selector\Atom $atom): bool => $atom instanceof RelatedTo))[0]->subject;

        return array_map(static fn (EffectPart $part): EffectPart => new EffectPart($part->roles, $part->abilities, $part->on, $part->byRelation, $relations, $command->resources->type, $subject), $parts);
    }

    /**
     * The actions, as abilities, on each resource named by ID, or in the whole realm.
     *
     * @param list<string> $actions
     *
     * @return list<EffectPart>
     */
    private function parts(array $actions, Selector\Selector $resources): array
    {
        $abilities = $this->named($actions);
        $ids = $resources->ids();

        if (null === $ids) {
            return [new EffectPart(abilities: $abilities)];
        }

        return array_map(static fn (string $id): EffectPart => new EffectPart(abilities: $abilities, on: new ResourceRef($resources->type, $id)), $ids->ids);
    }

    /**
     * The abilities that hold every action the patterns name, as the handler expands them: an ability
     * itself, an operation's ability for an operation. An operation that needs none, and a pattern that
     * names nothing, are given as written, so no-escalation weighs them, and finds them not held.
     *
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private function named(array $patterns): array
    {
        $known = [...$this->actions()->operations(), ...$this->actions()->abilities()];
        $named = [];

        foreach ($patterns as $pattern) {
            $matched = str_ends_with($pattern, '.*') ? array_values(array_filter($known, static fn (string $name): bool => str_starts_with($name, substr($pattern, 0, -1)))) : [$pattern];
            foreach ([] === $matched ? [$pattern] : $matched as $action) {
                $named[] = null === $this->actions()->operation($action) ? $action : ($this->actions()->abilityOf($action) ?? $action);
            }
        }

        $named = array_values(array_unique($named));
        sort($named, \SORT_STRING);

        return $named;
    }

    /**
     * The actions, built when first needed: they list the operations, whose catalogue lists this handler.
     */
    private function actions(): Actions
    {
        if ($this->actions instanceof Actions) {
            return $this->actions;
        }

        $actions = ($this->actions)();
        \assert($actions instanceof Actions);

        return $actions;
    }
}
