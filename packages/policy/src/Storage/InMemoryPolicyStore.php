<?php

declare(strict_types=1);

namespace YorvoID\Policy\Storage;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;
use YorvoID\Policy\AbilityChanges;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\RoleAssignment;

/**
 * @implements InMemoryStore<array{roles?: array<string, array<string, string>>, changes?: array<string, AbilityChanges>, assignments?: list<RoleAssignment>, versions?: array<string, int>}>
 *
 * @api
 */
final class InMemoryPolicyStore implements PolicyStore, InMemoryStore
{
    /** @var array<string, array<string, string>> Realm => name => label */
    private array $roles = [];

    /** @var array<string, AbilityChanges> By realm */
    private array $changes = [];

    /** @var list<RoleAssignment> */
    private array $assignments = [];

    /** @var array<string, int> By realm */
    private array $versions = [];

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
        $assignments = array_values(array_filter($this->assignments, static fn (RoleAssignment $assignment): bool => $assignment->realm->equals($realm) && $assignment->principalId->equals($principalId)));
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
        $this->assignments = [...array_values(array_filter($this->assignments, static fn (RoleAssignment $other): bool => !$other->sameAs($assignment))), $assignment];
    }

    public function unassign(Realm $realm, PrincipalId $principalId, string $role, ?ResourceRef $scope): bool
    {
        $before = \count($this->assignments);
        $this->assignments = array_values(array_filter($this->assignments, static fn (RoleAssignment $other): bool => !($other->realm->equals($realm) && $other->principalId->equals($principalId) && $other->role === $role && (string) $other->scope === (string) $scope)));

        return \count($this->assignments) < $before;
    }

    public function unassignRole(Realm $realm, string $role): void
    {
        $this->assignments = array_values(array_filter($this->assignments, static fn (RoleAssignment $other): bool => !($other->realm->equals($realm) && $other->role === $role)));
    }

    public function forgetPrincipal(Realm $realm, PrincipalId $principalId): void
    {
        $this->assignments = array_values(array_filter($this->assignments, static fn (RoleAssignment $other): bool => !($other->realm->equals($realm) && $other->principalId->equals($principalId))));
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
        return ['roles' => $this->roles, 'changes' => $this->changes, 'assignments' => $this->assignments, 'versions' => $this->versions];
    }

    public function restore(mixed $snapshot): void
    {
        $this->roles = $snapshot['roles'] ?? [];
        $this->changes = $snapshot['changes'] ?? [];
        $this->assignments = $snapshot['assignments'] ?? [];
        $this->versions = $snapshot['versions'] ?? [];
    }
}
