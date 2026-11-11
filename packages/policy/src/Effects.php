<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\Request;
use YorvoID\Policy\Change\AddGroupMember;
use YorvoID\Policy\Change\AssignGroupRole;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\CreateRole;
use YorvoID\Policy\Change\DeleteGroup;
use YorvoID\Policy\Change\DeleteRole;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Policy\Change\RemoveGroupMember;
use YorvoID\Policy\Change\ResetAbilities;
use YorvoID\Policy\Change\RevokeAbilities;
use YorvoID\Policy\Change\SetAbilities;
use YorvoID\Policy\Change\UnassignGroupRole;
use YorvoID\Policy\Change\UnassignRole;
use YorvoID\Relations\Change\AddRelation;
use YorvoID\Relations\Change\RemoveRelation;
use YorvoID\Relations\RelationDefinition;
use YorvoID\Relations\RelationSchema;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\Subject;
use YorvoID\Relations\TooDeep;

/**
 * What a change gives and takes (M2-13), computed once per request, so that no-escalation and the
 * step-up weigh the same snapshot.
 *
 * - A role given or taken, and a role a new role starts from.
 * - Abilities given to a role or taken from it; setting or resetting them counts only what changes.
 * - Membership of a group, as the roles of the group and of the groups it's within.
 * - A relation, as what it gives on its resource. A link (a parent, or a relation another is inherited
 *   through) also as what it brings along: the relations inherited through it, what those carry further
 *   down, which only a role holds for every descendant, and, for a parent, the roles on the new parent
 *   and above it.
 * - Deleting a group or a role, as everything it takes away.
 *
 * Not readonly: it keeps one per Roles in a static map.
 *
 * @internal
 */
final class Effects
{
    /** The relation roles follow to what a resource is within (Roles::within()) */
    private const string PARENT = 'parent';

    /** @var \WeakMap<Roles, self> */
    private static \WeakMap $shared;

    /** @var \WeakMap<Request, Effect> */
    private readonly \WeakMap $effects;

    private function __construct(
        private readonly Roles $roles,
    ) {
        $this->effects = new \WeakMap();
    }

    /**
     * The same for everything weighing changes with these roles.
     */
    public static function of(Roles $roles): self
    {
        self::$shared ??= new \WeakMap();

        return self::$shared[$roles] ??= new self($roles);
    }

    /**
     * @throws TooDeep When a relation's hierarchy is past the maximum depth, or has a cycle
     */
    public function on(Request $request): Effect
    {
        return $this->effects[$request] ??= $this->compute($request);
    }

    private function compute(Request $request): Effect
    {
        $command = $request->command;
        $realm = $request->realm;

        return match (true) {
            $command instanceof AssignRole, $command instanceof AssignGroupRole => new Effect(gives: [new EffectPart([$command->role])]),
            $command instanceof UnassignRole, $command instanceof UnassignGroupRole => new Effect(takes: [new EffectPart([$command->role])]),
            $command instanceof DeleteRole => new Effect(takes: [new EffectPart([$command->name])]),
            $command instanceof CreateRole => new Effect(gives: null === $command->from ? [] : [new EffectPart([$command->from])]),
            $command instanceof GrantAbilities => new Effect(gives: [new EffectPart(abilities: $command->abilities)]),
            $command instanceof RevokeAbilities => new Effect(takes: [new EffectPart(abilities: $command->abilities)]),
            $command instanceof SetAbilities => $this->set($request, $command),
            $command instanceof ResetAbilities => $this->reset($request, $command),
            $command instanceof AddGroupMember => new Effect(gives: [new EffectPart($this->roles->rolesOfGroup($realm, $command->group))]),
            $command instanceof RemoveGroupMember => new Effect(takes: [new EffectPart($this->roles->rolesOfGroup($realm, $command->group))]),
            $command instanceof DeleteGroup => new Effect(takes: $this->deleteGroup($request, $command->name)),
            $command instanceof AddRelation => new Effect(gives: $this->relation($request, new RelationTuple($command->realm, $command->object, $command->relation, $command->subject))),
            $command instanceof RemoveRelation => new Effect(takes: $this->relation($request, new RelationTuple($command->realm, $command->object, $command->relation, $command->subject))),
            default => new Effect(),
        };
    }

    /**
     * What the role gets and what it loses.
     */
    private function set(Request $request, SetAbilities $command): Effect
    {
        $current = $this->roles->abilitiesOf($request->realm, $command->role);

        return new Effect(
            gives: [new EffectPart(abilities: array_values(array_diff($command->abilities, $current)))],
            takes: [new EffectPart(abilities: array_values(array_diff($current, $command->abilities)))],
        );
    }

    /**
     * Back to the defaults: what was taken from a default role comes back, what was given goes.
     */
    private function reset(Request $request, ResetAbilities $command): Effect
    {
        $changes = $this->roles->changes($request->realm);
        $gives = [];
        $takes = [];

        foreach ($command->abilities ?? $changes->abilities() as $ability) {
            if ([] !== ($changes->revoke[$ability] ?? [])) {
                $gives[] = $ability;
            }

            if ([] !== ($changes->grant[$ability] ?? [])) {
                $takes[] = $ability;
            }
        }

        return new Effect([new EffectPart(abilities: $gives)], [new EffectPart(abilities: $takes)]);
    }

    /**
     * A relation, on its resource (C2): what it gives there and, if it's a link, what it brings along.
     *
     * @return list<EffectPart>
     */
    private function relation(Request $request, RelationTuple $tuple): array
    {
        $relations = $this->roles->relations();

        // Without relations, a relation's abilities aren't known: none is given.
        if (null === $relations) {
            return [];
        }

        $schema = $relations->schema();
        $on = $tuple->object;
        $definitions = $schema->type($on->type)->relations ?? [];
        $abilities = $schema->abilitiesGivenBy($on->type, $tuple->relation);
        // The relations of the type inherited through it, and what they imply.
        $inherited = [];

        foreach ($definitions as $relation) {
            if (\array_key_exists($tuple->relation, $relation->fromParents)) {
                array_push($abilities, ...$schema->abilitiesGivenBy($on->type, $relation->name));
                $inherited += $this->implied($definitions, $relation->name);
            }
        }

        // What it carries down to the resource's descendants: only a role, in the realm or on the resource
        // or above it, holds that for every one of them (C3).
        $parts = [new EffectPart(abilities: $abilities, on: $on), new EffectPart(abilities: $this->carriedDown($schema, $inherited), on: $on, byRelation: false)];

        if (self::PARENT !== $tuple->relation) {
            return $parts;
        }

        $parent = $tuple->subject->resource;
        $above = $relations->ancestors($request->realm, $parent) ?? throw new TooDeep(\sprintf('The parents of %s', $parent));
        // Those roles count on every descendant too (Roles::within()): only a role holds them for all (R1).
        $parts[] = new EffectPart($this->roles->rolesOn($request->realm, [$parent, ...$above]), on: $on, byRelation: false);

        return $parts;
    }

    /**
     * The abilities of every relation inherited, through any link, from these or what they imply, to a
     * fixpoint. The schema doesn't say what type a child is, so they're matched by name: that can count
     * too much, never too little.
     *
     * @param array<string, string> $names The relations inherited on the resource, and what they imply, by name
     *
     * @return list<string>
     */
    private function carriedDown(RelationSchema $schema, array $names): array
    {
        do {
            $before = \count($names);

            foreach ($schema->types() as $type) {
                foreach ($type->relations as $relation) {
                    if ([] !== array_intersect($relation->fromParents, $names)) {
                        $names += $this->implied($type->relations, $relation->name);
                    }
                }
            }
        } while (\count($names) > $before);

        $abilities = [];

        foreach ($schema->types() as $type) {
            foreach ($type->relations as $relation) {
                if ([] !== array_intersect($relation->fromParents, $names)) {
                    array_push($abilities, ...$schema->abilitiesGivenBy($type->name, $relation->name));
                }
            }
        }

        return $abilities;
    }

    /**
     * The relation and those of its type it implies: an editor is a viewer.
     *
     * @param array<string, RelationDefinition> $definitions The type's relations
     *
     * @return array<string, string> By name
     */
    private function implied(array $definitions, string $relation): array
    {
        $names = [$relation => $relation];
        $pending = [$relation];

        while (null !== $name = array_shift($pending)) {
            foreach ($definitions as $other) {
                if (!isset($names[$other->name]) && \in_array($name, $other->impliedBy, true)) {
                    $names[$other->name] = $other->name;
                    $pending[] = $other->name;
                }
            }
        }

        return $names;
    }

    /**
     * Everything deleting the group takes away (C4): the roles of the group and of those it's within;
     * whatever is given to the group, which goes with it; and what's given to the members of the groups
     * it's within, which its members lose. Each relation is weighed on its own resource.
     *
     * @return list<EffectPart>
     */
    private function deleteGroup(Request $request, string $group): array
    {
        $parts = [new EffectPart($this->roles->rolesOfGroup($request->realm, $group))];
        $relations = $this->roles->relations();

        if (null === $relations) {
            return $parts;
        }

        $subjects = [new Subject(GroupRelations::group($group)), GroupRelations::members($group)];

        foreach ($this->roles->groupsAbove($request->realm, $group) as $parent) {
            $subjects[] = GroupRelations::members($parent);
        }

        // A group's own relations among them are its members, whose roles are weighed above: as relations,
        // they give nothing.
        foreach ($relations->given($request->realm, $subjects) as $tuple) {
            array_push($parts, ...$this->relation($request, $tuple));
        }

        return $parts;
    }
}
