<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Operation\Operator;
use YorvoID\Policy\Attribute\SetAttribute;
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
use YorvoID\Relations\Relations;
use YorvoID\Relations\RelationSchema;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\Subject;
use YorvoID\Relations\TooDeep;

/**
 * Nobody gives or takes what they don't hold themselves (M2-13): each change is weighed by what it
 * does, to anyone, directly or through what follows from it, and refused when they couldn't give or
 * take that directly.
 *
 * - A role whose abilities they don't all have, a super role unless they have one, an ability they lack.
 * - Membership of a group, as the roles of the group and of the groups it's within.
 * - A relation, as what it gives on its resource. A link (a parent, or a relation another is inherited
 *   through) also as what it brings along: the relations inherited through it, what those carry further
 *   down, which only a role holds for every descendant, and, for a parent, the roles on the new parent
 *   and above it.
 * - Deleting a group or a role, as everything it takes away.
 * - Nobody sets their own security attributes: what they decide is the application's rules'.
 *
 * Managing roles doesn't make anyone more than they are. The host's operator is the final authority,
 * and isn't held to it.
 *
 * @api
 */
final readonly class NoEscalation implements ForbidElement
{
    /** The relation roles follow to what a resource is within (Roles::within()) */
    private const string PARENT = 'parent';

    public function __construct(
        private Roles $roles,
    ) {
    }

    public function name(): string
    {
        return 'yorvoid.policy.no_escalation';
    }

    public function matches(Request $request): bool
    {
        $command = $request->command;

        return !$request->actor instanceof Operator
            && ($command instanceof AssignRole || $command instanceof UnassignRole || ($command instanceof CreateRole && null !== $command->from) || $command instanceof DeleteRole
                || $command instanceof GrantAbilities || $command instanceof RevokeAbilities || $command instanceof SetAbilities || $command instanceof ResetAbilities
                || $command instanceof AssignGroupRole || $command instanceof UnassignGroupRole || $command instanceof AddGroupMember || $command instanceof RemoveGroupMember || $command instanceof DeleteGroup
                || $command instanceof AddRelation || $command instanceof RemoveRelation || $command instanceof SetAttribute);
    }

    public function evaluate(Request $request): Truth
    {
        $command = $request->command;

        if ($command instanceof SetAttribute) {
            return null !== $request->actor->principalId && $request->actor->principalId->equals($command->principalId)
                ? Truth::holds('Nobody sets their own security attributes.')
                : Truth::fails('They set someone else\'s.');
        }

        if ($command instanceof AddGroupMember || $command instanceof RemoveGroupMember) {
            return $this->roles($request, $this->roles->rolesOfGroup($request->realm, $command->group));
        }

        if ($command instanceof DeleteGroup) {
            return $this->deleteGroup($request, $command->name);
        }

        if ($command instanceof AddRelation || $command instanceof RemoveRelation) {
            return $this->relation($request, new RelationTuple($command->realm, $command->object, $command->relation, $command->subject));
        }

        [$role, $abilities] = match (true) {
            $command instanceof AssignRole, $command instanceof UnassignRole, $command instanceof AssignGroupRole, $command instanceof UnassignGroupRole => [$command->role, []],
            $command instanceof DeleteRole => [$command->name, []],
            $command instanceof CreateRole && null !== $command->from => [$command->from, []],
            $command instanceof GrantAbilities, $command instanceof RevokeAbilities => [null, $command->abilities],
            $command instanceof SetAbilities => [null, [...$command->abilities, ...$this->roles->abilitiesOf($request->realm, $command->role)]],
            $command instanceof ResetAbilities => [null, $command->abilities ?? $this->roles->changes($request->realm)->abilities()],
            default => [null, []],
        };

        return null === $role ? $this->abilities($request, $abilities) : $this->roles($request, [$role]);
    }

    /**
     * A relation given or taken, weighed on its resource (C2): what it gives there and, if it's a link,
     * what it brings along.
     */
    private function relation(Request $request, RelationTuple $tuple): Truth
    {
        $relations = $this->roles->relations();

        // Without relations, a relation's abilities aren't known: none is given.
        if (null === $relations) {
            return $this->abilities($request, []);
        }

        $schema = $relations->schema();
        $on = $request->on($tuple->object);
        $definitions = $schema->type($tuple->object->type)->relations ?? [];
        $abilities = $schema->abilitiesGivenBy($tuple->object->type, $tuple->relation);
        // The relations of the type inherited through it, and what they imply.
        $inherited = [];

        foreach ($definitions as $relation) {
            if (\array_key_exists($tuple->relation, $relation->fromParents)) {
                array_push($abilities, ...$schema->abilitiesGivenBy($tuple->object->type, $relation->name));
                $inherited += $this->implied($definitions, $relation->name);
            }
        }

        $truth = $this->abilities($on, $abilities);

        if (self::refuses($truth) || [] === $inherited && self::PARENT !== $tuple->relation) {
            return $truth;
        }

        // What it carries down to the resource's descendants: only a role, in the realm or on the resource
        // or above it, holds that for every one of them (C3).
        $truth = $this->abilities($on, $this->carriedDown($schema, $inherited), byRelation: false);

        if (self::refuses($truth) || self::PARENT !== $tuple->relation) {
            return $truth;
        }

        $parent = $tuple->subject->resource;
        $above = $relations->ancestors($request->realm, $parent) ?? throw new TooDeep(\sprintf('The parents of %s', $parent));

        // Those roles count on every descendant too (Roles::within()): only a role holds them for all (R1).
        return $this->roles($on, $this->roles->rolesOn($request->realm, [$parent, ...$above]), byRelation: false);
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
     */
    private function deleteGroup(Request $request, string $group): Truth
    {
        $truth = $this->roles($request, $this->roles->rolesOfGroup($request->realm, $group));
        $relations = $this->roles->relations();

        if (self::refuses($truth) || null === $relations) {
            return $truth;
        }

        $subjects = [new Subject(GroupRelations::group($group)), GroupRelations::members($group)];

        foreach ($this->roles->groupsAbove($request->realm, $group) as $parent) {
            $subjects[] = GroupRelations::members($parent);
        }

        // A group's own relations among them are its members, whose roles are weighed above: as relations,
        // they give nothing.
        foreach ($relations->given($request->realm, $subjects) as $tuple) {
            $truth = $this->relation($request, $tuple);

            if (self::refuses($truth)) {
                return $truth;
            }
        }

        return $truth;
    }

    /**
     * Each role's abilities they hold, and a super role only if they have one.
     *
     * @param list<string> $roles
     * @param bool         $byRelation Whether a relation to the request's resource holds an ability, or only a role does
     */
    private function roles(Request $request, array $roles, bool $byRelation = true): Truth
    {
        $abilities = [];

        foreach ($roles as $role) {
            if (true === $this->roles->role($request->realm, $role)?->super && !$this->hasSuperRole($request)) {
                return Truth::holds(\sprintf('"%s" is a super role: only someone with one gives it or takes it.', $role));
            }

            array_push($abilities, ...$this->roles->abilitiesOf($request->realm, $role));
        }

        return $this->abilities($request, $abilities, $byRelation);
    }

    /**
     * Every ability they hold, through a role or, unless only a role will do, a relation to the
     * request's resource.
     *
     * @param list<string> $abilities
     */
    private function abilities(Request $request, array $abilities, bool $byRelation = true): Truth
    {
        $missing = array_filter($this->roles->missing($request, array_unique($abilities)), fn (string $ability): bool => !$byRelation || !$this->related($request, $ability));

        if ([] === $missing) {
            return Truth::fails('They hold everything they change.');
        }

        $where = null === $request->resource ? '' : \sprintf(' on %s', $request->resource);

        return Truth::holds(\sprintf('They\'d change what they don\'t hold themselves%s: %s.', $where, implode(', ', $missing)));
    }

    /**
     * Whether a relation to the request's resource gives them the ability.
     */
    private function related(Request $request, string $ability): bool
    {
        $relations = $this->roles->relations();
        $principal = $request->actor->principalId;

        if (null === $relations || null === $request->resource || null === $principal) {
            return false;
        }

        foreach ($relations->schema()->granting($request->resource->type, $ability) as $relation) {
            if ($relations->check($request->realm, $request->resource, $relation, Subject::principal($principal)) ?? throw new TooDeep(\sprintf('The relations to %s', $request->resource))) {
                return true;
            }
        }

        return false;
    }

    private static function refuses(Truth $truth): bool
    {
        return TruthValue::Fails !== $truth->value;
    }

    private function hasSuperRole(Request $request): bool
    {
        foreach ($this->roles->rolesOf($request) as $role) {
            if (true === $this->roles->role($request->realm, $role)?->super) {
                return true;
            }
        }

        return false;
    }
}
