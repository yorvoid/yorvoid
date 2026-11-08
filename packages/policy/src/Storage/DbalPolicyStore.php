<?php

declare(strict_types=1);

namespace YorvoID\Policy\Storage;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;
use YorvoID\Policy\AbilityChanges;
use YorvoID\Policy\GroupRoleAssignment;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\RoleAssignment;

/**
 * The policy in the database: one row per created role, per change to a default, per assignment, and
 * the realm's version. Scopes are stored as "type:id", or "" for the whole realm.
 *
 * @api
 */
final readonly class DbalPolicyStore implements PolicyStore
{
    public const string ROLE_TABLE = 'policy_role';

    public const string CHANGE_TABLE = 'policy_ability_change';

    public const string ASSIGNMENT_TABLE = 'policy_role_assignment';

    public const string VERSION_TABLE = 'policy_version';

    public const string GROUP_TABLE = 'policy_group';

    public const string GROUP_ASSIGNMENT_TABLE = 'policy_group_role_assignment';

    private const array TIMES = ['assigned_at' => Types::DATETIMETZ_IMMUTABLE, 'expires_at' => Types::DATETIMETZ_IMMUTABLE];

    public function __construct(
        private Database $database,
    ) {
    }

    public function roles(Realm $realm): array
    {
        $roles = [];

        foreach ($this->database->connection->iterateAssociative(\sprintf('SELECT name, label FROM %s WHERE realm = ? ORDER BY name', $this->table(self::ROLE_TABLE)), [$realm->name]) as $row) {
            $roles[Row::string($row, 'name')] = Row::string($row, 'label');
        }

        return $roles;
    }

    public function saveRole(Realm $realm, string $name, string $label): void
    {
        if (0 === $this->database->connection->update($this->table(self::ROLE_TABLE), ['label' => $label], ['realm' => $realm->name, 'name' => $name])) {
            $this->database->connection->insert($this->table(self::ROLE_TABLE), ['realm' => $realm->name, 'name' => $name, 'label' => $label]);
        }
    }

    public function deleteRole(Realm $realm, string $name): void
    {
        $this->database->connection->delete($this->table(self::ROLE_TABLE), ['realm' => $realm->name, 'name' => $name]);
    }

    public function changes(Realm $realm): AbilityChanges
    {
        $lists = ['grant' => [], 'revoke' => []];

        foreach ($this->database->connection->iterateAssociative(\sprintf('SELECT ability, role, kind FROM %s WHERE realm = ?', $this->table(self::CHANGE_TABLE)), [$realm->name]) as $row) {
            $lists['grant' === Row::string($row, 'kind') ? 'grant' : 'revoke'][Row::string($row, 'ability')][] = Row::string($row, 'role');
        }

        return new AbilityChanges($lists['grant'], $lists['revoke']);
    }

    public function saveChanges(Realm $realm, AbilityChanges $changes): void
    {
        $this->database->connection->delete($this->table(self::CHANGE_TABLE), ['realm' => $realm->name]);

        foreach (['grant' => $changes->grant, 'revoke' => $changes->revoke] as $kind => $lists) {
            foreach ($lists as $ability => $roles) {
                foreach ($roles as $role) {
                    $this->database->connection->insert($this->table(self::CHANGE_TABLE), ['realm' => $realm->name, 'ability' => $ability, 'role' => $role, 'kind' => $kind]);
                }
            }
        }
    }

    public function assignments(Realm $realm, PrincipalId $principalId): array
    {
        $assignments = [];

        foreach ($this->database->connection->iterateAssociative(
            \sprintf('SELECT role, scope, assigned_at, expires_at FROM %s WHERE realm = ? AND principal_id = ? ORDER BY role, scope', $this->table(self::ASSIGNMENT_TABLE)),
            [$realm->name, $principalId->uuid->toRfc4122()],
        ) as $row) {
            $scope = Row::string($row, 'scope');
            $assignments[] = new RoleAssignment(
                $realm,
                $principalId,
                Row::string($row, 'role'),
                $this->time($row['assigned_at']),
                '' === $scope ? null : ResourceRef::parse($scope),
                null === $row['expires_at'] ? null : $this->time($row['expires_at']),
            );
        }

        return $assignments;
    }

    public function principalsWith(Realm $realm, string $role): array
    {
        $rows = $this->database->connection->fetchAllAssociative(\sprintf('SELECT DISTINCT principal_id FROM %s WHERE realm = ? AND role = ? ORDER BY principal_id', $this->table(self::ASSIGNMENT_TABLE)), [$realm->name, $role]);

        return array_map(static fn (array $row): PrincipalId => PrincipalId::fromString(Row::string($row, 'principal_id')), $rows);
    }

    public function assign(RoleAssignment $assignment): void
    {
        $this->unassign($assignment->realm, $assignment->principalId, $assignment->role, $assignment->scope);
        $this->database->connection->insert($this->table(self::ASSIGNMENT_TABLE), [
            'realm' => $assignment->realm->name,
            'principal_id' => $assignment->principalId->uuid->toRfc4122(),
            'role' => $assignment->role,
            'scope' => (string) $assignment->scope,
            'assigned_at' => $assignment->assignedAt,
            'expires_at' => $assignment->expiresAt,
        ], self::TIMES);
    }

    public function unassign(Realm $realm, PrincipalId $principalId, string $role, ?ResourceRef $scope): bool
    {
        return 0 < $this->database->connection->delete($this->table(self::ASSIGNMENT_TABLE), ['realm' => $realm->name, 'principal_id' => $principalId->uuid->toRfc4122(), 'role' => $role, 'scope' => (string) $scope]);
    }

    public function unassignRole(Realm $realm, string $role): void
    {
        $this->database->connection->delete($this->table(self::ASSIGNMENT_TABLE), ['realm' => $realm->name, 'role' => $role]);
        $this->database->connection->delete($this->table(self::GROUP_ASSIGNMENT_TABLE), ['realm' => $realm->name, 'role' => $role]);
    }

    public function groups(Realm $realm): array
    {
        $groups = [];

        foreach ($this->database->connection->iterateAssociative(\sprintf('SELECT name, label FROM %s WHERE realm = ? ORDER BY name', $this->table(self::GROUP_TABLE)), [$realm->name]) as $row) {
            $groups[Row::string($row, 'name')] = Row::string($row, 'label');
        }

        return $groups;
    }

    public function saveGroup(Realm $realm, string $name, string $label): void
    {
        if (0 === $this->database->connection->update($this->table(self::GROUP_TABLE), ['label' => $label], ['realm' => $realm->name, 'name' => $name])) {
            $this->database->connection->insert($this->table(self::GROUP_TABLE), ['realm' => $realm->name, 'name' => $name, 'label' => $label]);
        }
    }

    public function deleteGroup(Realm $realm, string $name): void
    {
        $this->database->connection->delete($this->table(self::GROUP_ASSIGNMENT_TABLE), ['realm' => $realm->name, 'group_name' => $name]);
        $this->database->connection->delete($this->table(self::GROUP_TABLE), ['realm' => $realm->name, 'name' => $name]);
    }

    public function groupAssignments(Realm $realm, array $groups): array
    {
        if ([] === $groups) {
            return [];
        }

        $assignments = [];

        foreach ($this->database->connection->iterateAssociative(
            \sprintf('SELECT group_name, role, scope, assigned_at, expires_at FROM %s WHERE realm = ? AND group_name IN (%s) ORDER BY group_name, role, scope', $this->table(self::GROUP_ASSIGNMENT_TABLE), implode(', ', array_fill(0, \count($groups), '?'))),
            [$realm->name, ...$groups],
        ) as $row) {
            $scope = Row::string($row, 'scope');
            $assignments[] = new GroupRoleAssignment(
                $realm,
                Row::string($row, 'group_name'),
                Row::string($row, 'role'),
                $this->time($row['assigned_at']),
                '' === $scope ? null : ResourceRef::parse($scope),
                null === $row['expires_at'] ? null : $this->time($row['expires_at']),
            );
        }

        return $assignments;
    }

    public function assignGroup(GroupRoleAssignment $assignment): void
    {
        $this->unassignGroup($assignment->realm, $assignment->group, $assignment->role, $assignment->scope);
        $this->database->connection->insert($this->table(self::GROUP_ASSIGNMENT_TABLE), [
            'realm' => $assignment->realm->name,
            'group_name' => $assignment->group,
            'role' => $assignment->role,
            'scope' => (string) $assignment->scope,
            'assigned_at' => $assignment->assignedAt,
            'expires_at' => $assignment->expiresAt,
        ], self::TIMES);
    }

    public function unassignGroup(Realm $realm, string $group, string $role, ?ResourceRef $scope): bool
    {
        return 0 < $this->database->connection->delete($this->table(self::GROUP_ASSIGNMENT_TABLE), ['realm' => $realm->name, 'group_name' => $group, 'role' => $role, 'scope' => (string) $scope]);
    }

    public function forgetPrincipal(Realm $realm, PrincipalId $principalId): void
    {
        $this->database->connection->delete($this->table(self::ASSIGNMENT_TABLE), ['realm' => $realm->name, 'principal_id' => $principalId->uuid->toRfc4122()]);
    }

    public function version(Realm $realm): int
    {
        $row = $this->database->connection->fetchAssociative(\sprintf('SELECT version FROM %s WHERE realm = ?', $this->table(self::VERSION_TABLE)), [$realm->name]);

        return false === $row ? 0 : Row::int($row, 'version');
    }

    public function bump(Realm $realm): int
    {
        // The update holds the row until the transaction ends: the realm's changes go one after another.
        $table = $this->table(self::VERSION_TABLE);

        if (0 === $this->database->connection->executeStatement(\sprintf('UPDATE %s SET version = version + 1 WHERE realm = ?', $table), [$realm->name])) {
            try {
                $this->database->connection->insert($table, ['realm' => $realm->name, 'version' => 1]);
            } catch (UniqueConstraintViolationException) {
                // Another change made the row first; wait for it like any other.
                $this->database->connection->executeStatement(\sprintf('UPDATE %s SET version = version + 1 WHERE realm = ?', $table), [$realm->name]);
            }
        }

        return $this->version($realm);
    }

    private function table(string $name): string
    {
        return $this->database->table($name);
    }

    private function time(mixed $value): \DateTimeImmutable
    {
        $time = Type::getType(Types::DATETIMETZ_IMMUTABLE)->convertToPHPValue($value, $this->database->connection->getDatabasePlatform());
        \assert($time instanceof \DateTimeImmutable);

        return $time;
    }
}
