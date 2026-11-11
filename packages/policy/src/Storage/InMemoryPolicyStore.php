<?php

declare(strict_types=1);

namespace YorvoID\Policy\Storage;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;
use YorvoID\Policy\AbilityChanges;
use YorvoID\Policy\GroupRoleAssignment;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\RoleAssignment;

/**
 * @implements InMemoryStore<array{roles?: array<string, array<string, string>>, changes?: array<string, AbilityChanges>, assignments?: array<string, RoleAssignment>, versions?: array<string, int>, groups?: array<string, array<string, string>>, groupAssignments?: array<string, GroupRoleAssignment>}>
 *
 * @api
 */
final class InMemoryPolicyStore implements PolicyStore, InMemoryStore
{
    /** @var array<string, array<string, string>> Realm => name => label */
    private array $roles = [];

    /** @var array<string, AbilityChanges> By realm */
    private array $changes = [];

    /** @var array<string, RoleAssignment> By realm, principal, role and scope */
    private array $assignments = [];

    /** @var array<string, int> By realm */
    private array $versions = [];

    /** @var array<string, array<string, string>> Realm => name => label */
    private array $groups = [];

    /** @var array<string, GroupRoleAssignment> By realm, group, role and scope */
    private array $groupAssignments = [];

    public function roles(Realm $realm): array
    {
        $roles = $this->roles[$realm->name] ?? [];
        ksort($roles, \SORT_STRING);

        return $roles;
    }

    public function saveRole(Realm $realm, string $name, string $label): void
    {
        $this->roles[$realm->name][$name] = $label;
    }

    public function deleteRole(Realm $realm, string $name): void
    {
        unset($this->roles[$realm->name][$name]);
    }

    public function changes(Realm $realm): AbilityChanges
    {
        return $this->changes[$realm->name] ?? new AbilityChanges();
    }

    public function saveChanges(Realm $realm, AbilityChanges $changes): void
    {
        $this->changes[$realm->name] = $changes;
    }

    public function assignments(Realm $realm, PrincipalId $principalId): array
    {
        $assignments = array_filter($this->assignments, static fn (RoleAssignment $assignment): bool => $assignment->realm->equals($realm) && $assignment->principalId->equals($principalId));
        usort($assignments, static fn (RoleAssignment $a, RoleAssignment $b): int => [$a->role, (string) $a->scope] <=> [$b->role, (string) $b->scope]);

        return $assignments;
    }

    public function principalsWith(Realm $realm, string $role): array
    {
        $ids = [];

        foreach ($this->assignments as $assignment) {
            if ($assignment->realm->equals($realm) && $assignment->role === $role) {
                $ids[$assignment->principalId->uuid->toRfc4122()] = $assignment->principalId;
            }
        }

        ksort($ids, \SORT_STRING);

        return array_values($ids);
    }

    public function assign(RoleAssignment $assignment): void
    {
        $this->assignments[self::key($assignment->realm, $assignment->principalId, $assignment->role, $assignment->scope)] = $assignment;
    }

    public function unassign(Realm $realm, PrincipalId $principalId, string $role, ?ResourceRef $scope): bool
    {
        $key = self::key($realm, $principalId, $role, $scope);
        $had = isset($this->assignments[$key]);
        unset($this->assignments[$key]);

        return $had;
    }

    public function unassignRole(Realm $realm, string $role): void
    {
        foreach ($this->assignments as $key => $assignment) {
            if ($assignment->realm->equals($realm) && $assignment->role === $role) {
                unset($this->assignments[$key]);
            }
        }

        foreach ($this->groupAssignments as $key => $assignment) {
            if ($assignment->realm->equals($realm) && $assignment->role === $role) {
                unset($this->groupAssignments[$key]);
            }
        }
    }

    public function groups(Realm $realm): array
    {
        $groups = $this->groups[$realm->name] ?? [];
        ksort($groups, \SORT_STRING);

        return $groups;
    }

    public function saveGroup(Realm $realm, string $name, string $label): void
    {
        $this->groups[$realm->name][$name] = $label;
    }

    public function deleteGroup(Realm $realm, string $name): void
    {
        unset($this->groups[$realm->name][$name]);

        foreach ($this->groupAssignments as $key => $assignment) {
            if ($assignment->realm->equals($realm) && $assignment->group === $name) {
                unset($this->groupAssignments[$key]);
            }
        }
    }

    public function groupAssignments(Realm $realm, array $groups): array
    {
        $assignments = array_filter($this->groupAssignments, static fn (GroupRoleAssignment $assignment): bool => $assignment->realm->equals($realm) && \in_array($assignment->group, $groups, true));
        usort($assignments, static fn (GroupRoleAssignment $a, GroupRoleAssignment $b): int => [$a->group, $a->role, (string) $a->scope] <=> [$b->group, $b->role, (string) $b->scope]);

        return $assignments;
    }

    public function rolesOn(Realm $realm, array $resources): array
    {
        $roles = [];

        foreach ([...$this->assignments, ...$this->groupAssignments] as $assignment) {
            foreach ($resources as $resource) {
                if ($assignment->realm->equals($realm) && null !== $assignment->scope && $resource->equals($assignment->scope)) {
                    $roles[] = $assignment->role;
                }
            }
        }

        $roles = array_unique($roles);
        sort($roles, \SORT_STRING);

        return $roles;
    }

    public function assignGroup(GroupRoleAssignment $assignment): void
    {
        $this->groupAssignments[self::groupKey($assignment->realm, $assignment->group, $assignment->role, $assignment->scope)] = $assignment;
    }

    public function unassignGroup(Realm $realm, string $group, string $role, ?ResourceRef $scope): bool
    {
        $key = self::groupKey($realm, $group, $role, $scope);
        $had = isset($this->groupAssignments[$key]);
        unset($this->groupAssignments[$key]);

        return $had;
    }

    public function forgetPrincipal(Realm $realm, PrincipalId $principalId): void
    {
        foreach ($this->assignments as $key => $assignment) {
            if ($assignment->realm->equals($realm) && $assignment->principalId->equals($principalId)) {
                unset($this->assignments[$key]);
            }
        }
    }

    public function version(Realm $realm): int
    {
        return $this->versions[$realm->name] ?? 0;
    }

    public function bump(Realm $realm): int
    {
        return $this->versions[$realm->name] = $this->version($realm) + 1;
    }

    public function snapshot(): mixed
    {
        return ['roles' => $this->roles, 'changes' => $this->changes, 'assignments' => $this->assignments, 'versions' => $this->versions, 'groups' => $this->groups, 'groupAssignments' => $this->groupAssignments];
    }

    public function restore(mixed $snapshot): void
    {
        $this->roles = $snapshot['roles'] ?? [];
        $this->changes = $snapshot['changes'] ?? [];
        $this->assignments = $snapshot['assignments'] ?? [];
        $this->versions = $snapshot['versions'] ?? [];
        $this->groups = $snapshot['groups'] ?? [];
        $this->groupAssignments = $snapshot['groupAssignments'] ?? [];
    }

    private static function key(Realm $realm, PrincipalId $principalId, string $role, ?ResourceRef $scope): string
    {
        return implode("\n", [$realm->name, $principalId->uuid->toRfc4122(), $role, (string) $scope]);
    }

    private static function groupKey(Realm $realm, string $group, string $role, ?ResourceRef $scope): string
    {
        return implode("\n", [$realm->name, $group, $role, (string) $scope]);
    }
}
