<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Relations\Relations;
use YorvoID\Relations\Subject;
use YorvoID\Relations\TooDeep;

/**
 * Which roles have which abilities in a realm, and which roles an actor has: the declared defaults with
 * the realm's changes, super roles, the "everyone" pseudo-role, assignments and role sources. What the
 * role permit decides by, and what pages that show or change the policy read.
 *
 * @api
 */
final readonly class Roles
{
    /** @var list<RoleSource> */
    private array $sources;

    /** @var \WeakMap<Request, list<string>> One reading per decision */
    private \WeakMap $memo;

    /**
     * @param iterable<RoleSource> $sources   Roles an actor has without their being stored
     * @param Relations|null       $relations For roles on a parent that count for its children, and groups
     */
    public function __construct(
        private PolicyCatalogue $catalogue,
        private PolicyStore $store,
        iterable $sources = [],
        private ?Relations $relations = null,
    ) {
        $list = [];

        foreach ($sources as $source) {
            $list[] = $source;
        }

        $this->sources = $list;
        $this->memo = new \WeakMap();
    }

    /**
     * Every role of the realm: "everyone" first, then the declared ones, then those created in the realm.
     *
     * @return array<string, string> Name => label
     */
    public function roles(Realm $realm): array
    {
        $roles = [Role::EVERYONE => 'Everyone'];

        foreach ($this->catalogue->roles() as $name => $role) {
            $roles[$name] = $role->label;
        }

        // A created role that a declaration took the name of later is the declared one now.
        return $roles + $this->store->roles($realm);
    }

    /**
     * The role, declared or created in the realm; null for "everyone" and for no role.
     */
    public function role(Realm $realm, string $name): ?Role
    {
        $declared = $this->catalogue->role($name);

        if (null !== $declared) {
            return $declared;
        }

        $label = Role::EVERYONE === $name ? null : $this->store->roles($realm)[$name] ?? null;

        return null === $label ? null : new Role($name, $label);
    }

    public function ability(string $name): ?Ability
    {
        return $this->catalogue->ability($name);
    }

    public function has(Realm $realm, string $role): bool
    {
        return isset($this->roles($realm)[$role]);
    }

    /**
     * @return list<string>
     *
     * @throws UnknownAbility
     */
    public function defaultRolesWith(string $ability): array
    {
        return ($this->catalogue->ability($ability) ?? throw new UnknownAbility($ability))->defaultRoles;
    }

    /**
     * The roles that have the ability in the realm, without super roles' (they have it unless it's strict).
     *
     * @return list<string>
     *
     * @throws UnknownAbility
     */
    public function rolesWith(Realm $realm, string $ability): array
    {
        return $this->store->changes($realm)->rolesWith($ability, $this->defaultRolesWith($ability));
    }

    /**
     * The abilities the role has in the realm, in the order declared, without what a super role has for
     * being one.
     *
     * @return list<string>
     */
    public function abilitiesOf(Realm $realm, string $role): array
    {
        $changes = $this->store->changes($realm);
        $abilities = [];

        foreach ($this->catalogue->abilities() as $name => $ability) {
            if (\in_array($role, $changes->rolesWith($name, $ability->defaultRoles), true)) {
                $abilities[] = $name;
            }
        }

        return $abilities;
    }

    public function changes(Realm $realm): AbilityChanges
    {
        return $this->store->changes($realm);
    }

    /**
     * Whether any of the roles has the ability in the realm: a super role has every one that isn't strict.
     *
     * @param list<string> $roles
     */
    public function allows(Realm $realm, array $roles, string $ability): bool
    {
        return null !== $this->why($realm, $roles, $ability);
    }

    /**
     * The roles the request's actor has: "everyone", what's assigned to it and to its groups in the
     * realm and counts for the request's moment and resource (or one the resource is within), and what
     * the role sources add.
     *
     * @return list<string>
     *
     * @throws TooDeep When the resource's parents or the actor's groups go deeper than they're followed
     */
    public function rolesOf(Request $request): array
    {
        if (isset($this->memo[$request])) {
            return $this->memo[$request];
        }

        $roles = [Role::EVERYONE];

        if ($request->actorInRealm()) {
            \assert(null !== $request->actor->principalId);
            $resources = $this->within($request);

            foreach ($this->store->assignments($request->realm, $request->actor->principalId) as $assignment) {
                if ($assignment->counts($request->at, ...$resources)) {
                    $roles[] = $assignment->role;
                }
            }

            foreach ($this->store->groupAssignments($request->realm, $this->groupsOf($request->realm, $request->actor->principalId)) as $assignment) {
                if ($assignment->counts($request->at, ...$resources)) {
                    $roles[] = $assignment->role;
                }
            }
        }

        foreach ($this->sources as $source) {
            foreach ($source->roles($request) as $role) {
                $roles[] = $role;
            }
        }

        return $this->memo[$request] = array_values(array_unique($roles));
    }

    /**
     * The groups the principal is a member of, through groups within groups too.
     *
     * @return list<string> Their names
     *
     * @throws TooDeep
     */
    public function groupsOf(Realm $realm, PrincipalId $principal): array
    {
        if (null === $this->relations) {
            return [];
        }

        $groups = $this->relations->memberships($realm, Subject::principal($principal), GroupRelations::TYPE, GroupRelations::MEMBER) ?? throw new TooDeep('The groups of a principal');

        return array_map(static fn (ResourceRef $group): string => $group->id, $groups);
    }

    /**
     * The roles given to the group and to every group it's within: what a member gets, whatever the scope.
     *
     * @return list<string>
     *
     * @throws TooDeep
     */
    public function rolesOfGroup(Realm $realm, string $group): array
    {
        $within = null === $this->relations ? [] : $this->relations->memberships($realm, GroupRelations::members($group), GroupRelations::TYPE, GroupRelations::MEMBER) ?? throw new TooDeep(\sprintf('The groups "%s" is within', $group));
        $groups = [$group, ...array_map(static fn (ResourceRef $parent): string => $parent->id, $within)];

        return array_values(array_unique(array_map(static fn (GroupRoleAssignment $assignment): string => $assignment->role, $this->store->groupAssignments($realm, $groups))));
    }

    /**
     * The relations, if roles are read with them.
     */
    public function relations(): ?Relations
    {
        return $this->relations;
    }

    /**
     * The request's resource and what it's within, nearest first.
     *
     * @return list<ResourceRef>
     *
     * @throws TooDeep
     */
    private function within(Request $request): array
    {
        if (null === $request->resource) {
            return [];
        }

        if (null === $this->relations) {
            return [$request->resource];
        }

        return [$request->resource, ...$this->relations->ancestors($request->realm, $request->resource) ?? throw new TooDeep(\sprintf('The parents of %s', $request->resource))];
    }

    /**
     * Whether the request's actor holds its ability, and why: what the role permit gives.
     */
    public function decide(Request $request): Truth
    {
        $ability = $request->ability;

        if (null === $ability) {
            return Truth::fails('No ability is asked for.');
        }

        $declared = $this->catalogue->ability($ability);

        if (null === $declared) {
            return Truth::fails(\sprintf('There\'s no ability "%s".', $ability));
        }

        $roles = $this->rolesOf($request);
        $why = $this->why($request->realm, $roles, $ability);

        if (null !== $why) {
            return Truth::holds($why);
        }

        $super = array_filter($roles, fn (string $role): bool => true === $this->catalogue->role($role)?->super);

        return Truth::fails($declared->strict && [] !== $super
            ? \sprintf('None of their roles has "%s": it\'s strict, so a super role has it only when it\'s granted to it.', $ability)
            : \sprintf('None of their roles has "%s".', $ability));
    }

    /**
     * The abilities of these the request's actor doesn't hold.
     *
     * @param array<int, string> $abilities
     *
     * @return list<string> In their order
     */
    public function missing(Request $request, array $abilities): array
    {
        $roles = $this->rolesOf($request);

        return array_values(array_filter($abilities, fn (string $ability): bool => !$this->allows($request->realm, $roles, $ability)));
    }

    /**
     * @param list<string> $roles
     */
    private function why(Realm $realm, array $roles, string $ability): ?string
    {
        $declared = $this->catalogue->ability($ability);

        if (null === $declared) {
            return null;
        }

        if (!$declared->strict) {
            foreach ($roles as $role) {
                if (true === $this->catalogue->role($role)?->super) {
                    return \sprintf('Their role "%s" is a super role: it has every ability that isn\'t strict.', $role);
                }
            }
        }

        foreach ($this->store->changes($realm)->rolesWith($ability, $declared->defaultRoles) as $role) {
            if (\in_array($role, $roles, true)) {
                return Role::EVERYONE === $role ? \sprintf('Everyone has "%s".', $ability) : \sprintf('Their role "%s" has "%s".', $role, $ability);
            }
        }

        return null;
    }
}
