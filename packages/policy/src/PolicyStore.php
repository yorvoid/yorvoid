<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A realm's policy as data: the roles created in it, its changes to the declared defaults, who has
 * which role, and a version that every change increments (AUZ-009). Changes run in a transaction that
 * increments the version first, which serializes them per realm.
 *
 * @api
 */
interface PolicyStore
{
    /**
     * @return array<string, string> The roles created in the realm, name => label, sorted by name
     */
    public function roles(Realm $realm): array;

    /** Creates the role, or relabels it */
    public function saveRole(Realm $realm, string $name, string $label): void;

    public function deleteRole(Realm $realm, string $name): void;

    public function changes(Realm $realm): AbilityChanges;

    /** Replaces the realm's changes */
    public function saveChanges(Realm $realm, AbilityChanges $changes): void;

    /**
     * @return list<RoleAssignment> Expired ones too, by role, then scope
     */
    public function assignments(Realm $realm, PrincipalId $principalId): array;

    /**
     * @return list<PrincipalId> Sorted
     */
    public function principalsWith(Realm $realm, string $role): array;

    /** Adds it, or replaces the one with its principal, role and scope */
    public function assign(RoleAssignment $assignment): void;

    /**
     * @return bool Whether there was one
     */
    public function unassign(Realm $realm, PrincipalId $principalId, string $role, ?ResourceRef $scope): bool;

    /** Takes the role from everyone who has it, groups too, e.g. when it's deleted */
    public function unassignRole(Realm $realm, string $role): void;

    /** Takes every role from the principal, e.g. when it's deleted */
    public function forgetPrincipal(Realm $realm, PrincipalId $principalId): void;

    /**
     * @return array<string, string> The realm's groups, name => label, sorted by name
     */
    public function groups(Realm $realm): array;

    /** Creates the group, or relabels it */
    public function saveGroup(Realm $realm, string $name, string $label): void;

    /** Deletes the group with the roles given to it */
    public function deleteGroup(Realm $realm, string $name): void;

    /**
     * @param list<string> $groups
     *
     * @return list<GroupRoleAssignment> Expired ones too, by group, role, then scope
     */
    public function groupAssignments(Realm $realm, array $groups): array;

    /** Adds it, or replaces the one with its group, role and scope */
    public function assignGroup(GroupRoleAssignment $assignment): void;

    /**
     * @return bool Whether there was one
     */
    public function unassignGroup(Realm $realm, string $group, string $role, ?ResourceRef $scope): bool;

    /** 0 before the first change */
    public function version(Realm $realm): int;

    /**
     * Increments the version and holds it until the transaction ends, so changes of one realm don't interleave.
     *
     * @return int The new version
     */
    public function bump(Realm $realm): int;
}
