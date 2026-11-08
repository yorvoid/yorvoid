<?php

declare(strict_types=1);

namespace YorvoID\Policy\Storage;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Policy\AbilityChanges;
use YorvoID\Policy\GroupRoleAssignment;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\RoleAssignment;

/**
 * A policy store whose reads are kept against the realm's policy version (AUZ-031): read once in a
 * request, however many decisions read them, and from a shared pool if the cache has one. The version
 * is read once in a request too, so a request decides by one state of the policy. Once the request
 * changes a realm's policy, it reads it from the store until reset.
 *
 * @api
 */
final class CachedPolicyStore implements PolicyStore
{
    /** @var array<string, int> By realm */
    private array $versions = [];

    /** @var array<string, true> The realms this request changed */
    private array $changed = [];

    public function __construct(
        private readonly PolicyStore $store,
        private readonly VersionedCache $cache,
    ) {
    }

    public function roles(Realm $realm): array
    {
        return $this->read($realm, 'roles', fn (): array => $this->store->roles($realm), self::isLabels(...));
    }

    public function saveRole(Realm $realm, string $name, string $label): void
    {
        $this->changed($realm)->saveRole($realm, $name, $label);
    }

    public function deleteRole(Realm $realm, string $name): void
    {
        $this->changed($realm)->deleteRole($realm, $name);
    }

    public function changes(Realm $realm): AbilityChanges
    {
        return $this->read($realm, 'changes', fn (): AbilityChanges => $this->store->changes($realm), static fn (mixed $changes): bool => $changes instanceof AbilityChanges);
    }

    public function saveChanges(Realm $realm, AbilityChanges $changes): void
    {
        $this->changed($realm)->saveChanges($realm, $changes);
    }

    public function assignments(Realm $realm, PrincipalId $principalId): array
    {
        return $this->read($realm, \sprintf('assignments.%s', $principalId), fn (): array => $this->store->assignments($realm, $principalId), static fn (mixed $assignments): bool => self::isListOf($assignments, static fn (mixed $assignment): bool => $assignment instanceof RoleAssignment && $assignment->realm->equals($realm) && $assignment->principalId->equals($principalId)));
    }

    public function principalsWith(Realm $realm, string $role): array
    {
        return $this->read($realm, \sprintf('principals.%s', $role), fn (): array => $this->store->principalsWith($realm, $role), static fn (mixed $principals): bool => self::isListOf($principals, static fn (mixed $principal): bool => $principal instanceof PrincipalId));
    }

    public function assign(RoleAssignment $assignment): void
    {
        $this->changed($assignment->realm)->assign($assignment);
    }

    public function unassign(Realm $realm, PrincipalId $principalId, string $role, ?ResourceRef $scope): bool
    {
        return $this->changed($realm)->unassign($realm, $principalId, $role, $scope);
    }

    public function unassignRole(Realm $realm, string $role): void
    {
        $this->changed($realm)->unassignRole($realm, $role);
    }

    public function forgetPrincipal(Realm $realm, PrincipalId $principalId): void
    {
        $this->changed($realm)->forgetPrincipal($realm, $principalId);
    }

    public function groups(Realm $realm): array
    {
        return $this->read($realm, 'groups', fn (): array => $this->store->groups($realm), self::isLabels(...));
    }

    public function saveGroup(Realm $realm, string $name, string $label): void
    {
        $this->changed($realm)->saveGroup($realm, $name, $label);
    }

    public function deleteGroup(Realm $realm, string $name): void
    {
        $this->changed($realm)->deleteGroup($realm, $name);
    }

    public function groupAssignments(Realm $realm, array $groups): array
    {
        return $this->read($realm, \sprintf('group_assignments.%s', implode('|', $groups)), fn (): array => $this->store->groupAssignments($realm, $groups), static fn (mixed $assignments): bool => self::isListOf($assignments, static fn (mixed $assignment): bool => $assignment instanceof GroupRoleAssignment && $assignment->realm->equals($realm) && \in_array($assignment->group, $groups, true)));
    }

    public function assignGroup(GroupRoleAssignment $assignment): void
    {
        $this->changed($assignment->realm)->assignGroup($assignment);
    }

    public function unassignGroup(Realm $realm, string $group, string $role, ?ResourceRef $scope): bool
    {
        return $this->changed($realm)->unassignGroup($realm, $group, $role, $scope);
    }

    public function version(Realm $realm): int
    {
        if ($this->changed[$realm->name] ?? false) {
            return $this->store->version($realm);
        }

        return $this->versions[$realm->name] ??= $this->store->version($realm);
    }

    public function bump(Realm $realm): int
    {
        return $this->changed($realm)->bump($realm);
    }

    /**
     * Forgets the versions read and the changes made: between requests, and between a worker's messages.
     */
    public function reset(): void
    {
        $this->versions = [];
        $this->changed = [];
    }

    /**
     * The store, once the realm is marked changed: from now on the request reads it from the store.
     */
    private function changed(Realm $realm): PolicyStore
    {
        $this->changed[$realm->name] = true;

        return $this->store;
    }

    /**
     * @template T
     *
     * @param \Closure(): T         $read
     * @param \Closure(mixed): bool $valid
     *
     * @return T
     */
    private function read(Realm $realm, string $what, \Closure $read, \Closure $valid): mixed
    {
        if ($this->changed[$realm->name] ?? false) {
            return $read();
        }

        // The version first: what's read after it is at least as new as it, never older.
        return $this->cache->get($realm, 'policy', $this->version($realm), $what, $read, $valid);
    }

    private static function isLabels(mixed $labels): bool
    {
        return \is_array($labels) && [] === array_filter($labels, static fn (mixed $label, int|string $name): bool => !\is_string($name) || !\is_string($label), \ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param \Closure(mixed): bool $each
     */
    private static function isListOf(mixed $list, \Closure $each): bool
    {
        return \is_array($list) && array_is_list($list) && [] === array_filter($list, static fn (mixed $item): bool => !$each($item));
    }
}
