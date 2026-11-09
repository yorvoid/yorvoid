<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting\Mutation;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\AbilityChanges;
use YorvoID\Policy\GroupRoleAssignment;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\RoleAssignment;

/**
 * The policy store, as the mutant on changes what's read: the grants, the assignments' scopes, the
 * groups' roles. What's written isn't changed.
 *
 * @internal
 */
final readonly class MutatedPolicyStore implements PolicyStore
{
    public function __construct(
        public PolicyStore $inner,
        private Mutants $mutants,
    ) {
    }

    public function changes(Realm $realm): AbilityChanges
    {
        $changes = $this->inner->changes($realm);

        foreach ($this->mutants->grants() as [$ability, $role, $granted, $defaults]) {
            $changes = $changes->change($ability, $role, $granted, $defaults);
        }

        return $changes;
    }

    public function assignments(Realm $realm, PrincipalId $principalId): array
    {
        $assignments = $this->inner->assignments($realm, $principalId);

        if (!$this->mutants->is(MutationOperator::ScopeWidens)) {
            return $assignments;
        }

        return array_map(static fn (RoleAssignment $one): RoleAssignment => new RoleAssignment($one->realm, $one->principalId, $one->role, $one->assignedAt, null, $one->expiresAt), $assignments);
    }

    public function groupAssignments(Realm $realm, array $groups): array
    {
        if ($this->mutants->is(MutationOperator::GroupsDontCount)) {
            return [];
        }

        $assignments = $this->inner->groupAssignments($realm, $groups);

        if (!$this->mutants->is(MutationOperator::ScopeWidens)) {
            return $assignments;
        }

        return array_map(static fn (GroupRoleAssignment $one): GroupRoleAssignment => new GroupRoleAssignment($one->realm, $one->group, $one->role, $one->assignedAt, null, $one->expiresAt), $assignments);
    }

    public function roles(Realm $realm): array
    {
        return $this->inner->roles($realm);
    }

    public function saveRole(Realm $realm, string $name, string $label): void
    {
        $this->inner->saveRole($realm, $name, $label);
    }

    public function deleteRole(Realm $realm, string $name): void
    {
        $this->inner->deleteRole($realm, $name);
    }

    public function saveChanges(Realm $realm, AbilityChanges $changes): void
    {
        $this->inner->saveChanges($realm, $changes);
    }

    public function principalsWith(Realm $realm, string $role): array
    {
        return $this->inner->principalsWith($realm, $role);
    }

    public function assign(RoleAssignment $assignment): void
    {
        $this->inner->assign($assignment);
    }

    public function unassign(Realm $realm, PrincipalId $principalId, string $role, ?ResourceRef $scope): bool
    {
        return $this->inner->unassign($realm, $principalId, $role, $scope);
    }

    public function unassignRole(Realm $realm, string $role): void
    {
        $this->inner->unassignRole($realm, $role);
    }

    public function forgetPrincipal(Realm $realm, PrincipalId $principalId): void
    {
        $this->inner->forgetPrincipal($realm, $principalId);
    }

    public function groups(Realm $realm): array
    {
        return $this->inner->groups($realm);
    }

    public function saveGroup(Realm $realm, string $name, string $label): void
    {
        $this->inner->saveGroup($realm, $name, $label);
    }

    public function deleteGroup(Realm $realm, string $name): void
    {
        $this->inner->deleteGroup($realm, $name);
    }

    public function assignGroup(GroupRoleAssignment $assignment): void
    {
        $this->inner->assignGroup($assignment);
    }

    public function unassignGroup(Realm $realm, string $group, string $role, ?ResourceRef $scope): bool
    {
        return $this->inner->unassignGroup($realm, $group, $role, $scope);
    }

    public function version(Realm $realm): int
    {
        return $this->inner->version($realm);
    }

    public function bump(Realm $realm): int
    {
        return $this->inner->bump($realm);
    }
}
