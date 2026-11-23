<?php

declare(strict_types=1);

namespace YorvoID\Delegation;

use YorvoID\Core\Authorization\Actions;
use YorvoID\Core\Authorization\DeclaresEffects;
use YorvoID\Core\Authorization\Effect;
use YorvoID\Core\Authorization\EffectPart;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Delegation\Change\IssueDelegation;
use YorvoID\Delegation\Change\RevokeDelegation;
use YorvoID\Delegation\Change\RevokeDelegationsOf;

/**
 * What the delegation commands give and take (D-51). Issuing gives its actions: on each resource it
 * names by ID, or in the whole realm, so no-escalation weighs whether a root delegator holds them there
 * (a delegation passed on is within its parent instead, AttenuatedByParent), and step-up asks a proof
 * for a strict one. Revoking gives nothing: taking delegated authority back never escalates, and never
 * waits for a proof.
 *
 * @api
 *
 * @experimental
 */
final readonly class DelegationEffects implements DeclaresEffects
{
    public function __construct(
        private Actions|\Closure $actions,
    ) {
    }

    public function declares(): array
    {
        return [IssueDelegation::class, RevokeDelegation::class, RevokeDelegationsOf::class];
    }

    public function effectOf(Request $request): Effect
    {
        $command = $request->command;

        if (!$command instanceof IssueDelegation) {
            return Effect::none();
        }

        $abilities = $this->named($command->actions);
        $ids = $command->resources->ids();

        if (null === $ids) {
            return new Effect(gives: [new EffectPart(abilities: $abilities)]);
        }

        return new Effect(gives: array_map(static fn (string $id): EffectPart => new EffectPart(abilities: $abilities, on: new ResourceRef($command->resources->type, $id)), $ids->ids));
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
