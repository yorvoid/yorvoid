<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\Ground;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\RoleGrant;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\ViewAs\Beyond;
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

    /** @var \WeakMap<Request, list<array{string, ResourceRef|null, string|null, string|null}>> One reading per decision, or per batch: each role, the resource and group it's had on and through, and its source */
    private \WeakMap $memo;

    /** @var \WeakMap<Request, AbilityChanges> The realm's changes, read once for a batch */
    private \WeakMap $changes;

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
        $this->changes = new \WeakMap();
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
        return array_values(array_unique(array_column($this->held($request), 0)));
    }

    /**
     * Each role the request's actor has, as it has it.
     *
     * @return list<array{string, ResourceRef|null, string|null, string|null}> The role, the resource it's on, the group it's through, its source
     *
     * @throws TooDeep
     */
    private function held(Request $request): array
    {
        if (isset($this->memo[$request])) {
            return $this->memo[$request];
        }

        if (!$request->actorInRealm()) {
            return $this->memo[$request] = $this->compose($request, [], []);
        }

        \assert(null !== $request->actor->principalId);
        $resources = $this->within($request);
        $assignments = [
            ...$this->store->assignments($request->realm, $request->actor->principalId),
            ...$this->store->groupAssignments($request->realm, $this->groupsOf($request->realm, $request->actor->principalId)),
        ];

        return $this->memo[$request] = $this->compose($request, $assignments, $resources);
    }

    /**
     * Reads the roles of many requests at once (AUZ-032): each actor's assignments and groups once, and
     * the parents of every resource a level at a time. What can't be read is left for rolesOf() to
     * read, and report, request by request.
     *
     * @param list<Request> $requests
     */
    public function preload(array $requests): void
    {
        /** @var array<string, array<string, list<Request>>> $actors By realm, then principal */
        $actors = [];
        /** @var array<string, AbilityChanges> $changes By realm */
        $changes = [];

        foreach ($requests as $request) {
            $this->changes[$request] = $changes[$request->realm->name] ??= $this->store->changes($request->realm);

            if ($request->actorInRealm() && !isset($this->memo[$request])) {
                $actors[$request->realm->name][(string) $request->actor->principalId][] = $request;
            }
        }

        foreach ($actors as $principals) {
            foreach ($principals as $batch) {
                $realm = $batch[0]->realm;
                $principal = $batch[0]->actor->principalId;
                \assert(null !== $principal);
                $assignments = [
                    ...$this->store->assignments($realm, $principal),
                    ...$this->store->groupAssignments($realm, $this->groupsOf($realm, $principal)),
                ];
                $resources = [];

                foreach ($batch as $request) {
                    if (null !== $request->resource) {
                        $resources[] = $request->resource;
                    }
                }

                $ancestors = null === $this->relations ? [] : $this->relations->ancestorsOfMany($realm, $resources);

                foreach ($batch as $request) {
                    $resource = $request->resource;

                    if (null === $resource) {
                        $this->memo[$request] = $this->compose($request, $assignments, []);
                    } elseif (null === $this->relations) {
                        $this->memo[$request] = $this->compose($request, $assignments, [$resource]);
                    } elseif (null !== $ancestors[(string) $resource]) {
                        $this->memo[$request] = $this->compose($request, $assignments, [$resource, ...$ancestors[(string) $resource]]);
                    }
                }
            }
        }
    }

    /**
     * "Everyone", the assignments that count for the request's moment and resources, and what the role
     * sources add.
     *
     * @param list<RoleAssignment|GroupRoleAssignment> $assignments
     * @param list<ResourceRef>                        $resources   The request's resource and what it's within
     *
     * @return list<array{string, ResourceRef|null, string|null, string|null}>
     */
    private function compose(Request $request, array $assignments, array $resources): array
    {
        $held = [[Role::EVERYONE, null, null, null]];

        foreach ($assignments as $assignment) {
            if ($assignment->counts($request->at, ...$resources)) {
                $held[] = [$assignment->role, $assignment->scope, $assignment instanceof GroupRoleAssignment ? $assignment->group : null, null];
            }
        }

        foreach ($this->sources as $source) {
            foreach ($source->roles($request) as $role) {
                $held[] = [$role, null, null, get_debug_type($source)];
            }
        }

        return $held;
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

        $held = $this->held($request);
        $roles = $this->rolesOf($request);
        $changes = $this->changes[$request] ?? $this->store->changes($request->realm);
        $why = $this->why($request->realm, $roles, $ability, $changes);

        if (null !== $why) {
            return Truth::holds($why, ...$this->grounds($held, $declared, $changes));
        }

        $super = array_filter($roles, fn (string $role): bool => true === $this->catalogue->role($role)?->super);

        return Truth::fails($declared->strict && [] !== $super
            ? \sprintf('None of their roles has "%s": it\'s strict, so a super role has it only when it\'s granted to it.', $ability)
            : \sprintf('None of their roles has "%s".', $ability));
    }

    /**
     * Every role held that has the ability, and how: by its default, granted in the realm, or as a
     * super role (G5).
     *
     * @param list<array{string, ResourceRef|null, string|null, string|null}> $held
     *
     * @return list<Ground>
     */
    private function grounds(array $held, Ability $ability, AbilityChanges $changes): array
    {
        $with = $changes->rolesWith($ability->name, $ability->defaultRoles);
        $grounds = [];
        $seen = [];

        foreach ($held as [$role, $on, $group, $source]) {
            $grant = match (true) {
                \in_array($role, $with, true) => \in_array($role, $ability->defaultRoles, true) ? RoleGrant::Default : RoleGrant::Granted,
                !$ability->strict && true === $this->catalogue->role($role)?->super => RoleGrant::Super,
                default => null,
            };

            // Each way once: the role decides how it has the ability.
            $way = json_encode([$role, $on, $group, $source], \JSON_THROW_ON_ERROR);

            if (null !== $grant && !($seen[$way] ?? false)) {
                $seen[$way] = true;
                $grounds[] = Ground::role($role, $grant, $on, $group, $source);
            }
        }

        return $grounds;
    }

    /**
     * What the target's roles give and the viewer's don't, in the whole realm and on each resource the
     * target has a role on: what a view as the target refuses though the target would be permitted.
     * Only roles are read; a relation or a rule that permits the target isn't listed.
     *
     * @return list<Beyond> In the catalogue's order, the whole realm first
     *
     * @throws TooDeep
     */
    public function beyond(Realm $realm, Actor $viewer, Actor $target, Origin $origin, \DateTimeImmutable $at): array
    {
        $scopes = [null];
        // The roles don't depend on the ability asked: any names the request.
        $inRealm = Request::forAbility(YorvoIDDeclarations::POLICY_VIEW_AS, $realm, null, $target, $origin, $at);

        if ($inRealm->actorInRealm()) {
            \assert(null !== $target->principalId);

            foreach ([...$this->store->assignments($realm, $target->principalId), ...$this->store->groupAssignments($realm, $this->groupsOf($realm, $target->principalId))] as $assignment) {
                if (null !== $assignment->scope && $assignment->counts($at, $assignment->scope)) {
                    $scopes[(string) $assignment->scope] = $assignment->scope;
                }
            }
        }

        $abilities = array_keys($this->catalogue->abilities());
        $beyond = [];
        $everywhere = [];

        foreach ($scopes as $scope) {
            $asTarget = Request::forAbility(YorvoIDDeclarations::POLICY_VIEW_AS, $realm, $scope, $target, $origin, $at);
            $held = array_diff($abilities, $this->missing($asTarget, $abilities), $everywhere);

            foreach ($this->missing($asTarget->by($viewer), $held) as $ability) {
                $beyond[] = new Beyond($ability, $scope);

                if (null === $scope) {
                    $everywhere[] = $ability;
                }
            }
        }

        return $beyond;
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
     * @param list<string>        $roles
     * @param AbilityChanges|null $changes The realm's, if read already
     */
    private function why(Realm $realm, array $roles, string $ability, ?AbilityChanges $changes = null): ?string
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

        foreach (($changes ?? $this->store->changes($realm))->rolesWith($ability, $declared->defaultRoles) as $role) {
            if (\in_array($role, $roles, true)) {
                return Role::EVERYONE === $role ? \sprintf('Everyone has "%s".', $ability) : \sprintf('Their role "%s" has "%s".', $role, $ability);
            }
        }

        return null;
    }
}
