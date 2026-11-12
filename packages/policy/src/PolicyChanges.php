<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Core\Authorization\RequiresForbids;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Core\Time;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\CreateRole;
use YorvoID\Policy\Change\DeleteRole;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Policy\Change\RelabelRole;
use YorvoID\Policy\Change\ResetAbilities;
use YorvoID\Policy\Change\RevokeAbilities;
use YorvoID\Policy\Change\SetAbilities;
use YorvoID\Policy\Change\UnassignRole;

/**
 * Changes a realm's policy: its roles, who has them, and which roles have which abilities. Each change
 * runs through Operations::run(), in one transaction that increments the realm's policy version first
 * (so changes of one realm go one after another), and is recorded in the security log.
 *
 * @api
 */
final readonly class PolicyChanges implements OperationHandler, RequiresForbids
{
    private Roles $roles;

    public function __construct(
        private PolicyCatalogue $catalogue,
        private PolicyStore $store,
        private PrincipalStore $principals,
        private SecurityLog $log,
        private Transactions $transactions,
        private ClockInterface $clock = new NativeClock(),
    ) {
        $this->roles = new Roles($catalogue, $store);
    }

    public function operations(): array
    {
        return [
            CreateRole::class => $this->createRole(...),
            RelabelRole::class => $this->relabelRole(...),
            DeleteRole::class => $this->deleteRole(...),
            AssignRole::class => $this->assign(...),
            UnassignRole::class => $this->unassign(...),
            GrantAbilities::class => $this->grant(...),
            RevokeAbilities::class => $this->revoke(...),
            SetAbilities::class => $this->set(...),
            ResetAbilities::class => $this->reset(...),
        ];
    }

    /**
     * @throws InvalidRole When the name or label isn't valid, or a role has the name
     * @throws UnknownRole When the role to start from isn't one
     */
    private function createRole(CreateRole $command, Permit $permit, Origin $origin): Role
    {
        $permit->use($command);
        $role = new Role($command->name, $command->label);

        if ($this->roles->has($command->realm, $role->name)) {
            throw new InvalidRole($role->name, \sprintf('The role "%s" exists.', $role->name));
        }

        if (null !== $command->from) {
            $this->known($command->realm, $command->from);
        }

        return $this->transactions->run(function () use ($command, $role, $permit, $origin): Role {
            $this->store->bump($command->realm);
            // A role deleted earlier left nothing, but changes made with its name before go too.
            $changes = $this->store->changes($command->realm)->withoutRole($role->name);

            foreach (null === $command->from ? [] : $this->roles->abilitiesOf($command->realm, $command->from) as $ability) {
                $changes = $changes->change($ability, $role->name, true, $this->roles->defaultRolesWith($ability));
            }

            $this->store->saveRole($command->realm, $role->name, $role->label);
            $this->store->saveChanges($command->realm, $changes);
            $this->record($command->realm, 'yorvoid.role.create', $permit, $origin, 'role', $role->name, self::present(['label' => $role->label, 'from' => $command->from]));

            return $role;
        });
    }

    /**
     * @throws InvalidRole When the label isn't valid, or the role is declared
     * @throws UnknownRole
     */
    private function relabelRole(RelabelRole $command, Permit $permit, Origin $origin): Role
    {
        $permit->use($command);
        $this->created($command->realm, $command->name, 'relabelled');
        $role = new Role($command->name, $command->label);

        return $this->transactions->run(function () use ($command, $role, $permit, $origin): Role {
            $this->store->bump($command->realm);
            $this->store->saveRole($command->realm, $role->name, $role->label);
            $this->record($command->realm, 'yorvoid.role.relabel', $permit, $origin, 'role', $role->name, ['label' => $role->label]);

            return $role;
        });
    }

    /**
     * Takes the role from everyone, with its changes, and deletes it.
     *
     * @throws InvalidRole When the role is declared
     * @throws UnknownRole
     */
    private function deleteRole(DeleteRole $command, Permit $permit, Origin $origin): void
    {
        $permit->use($command);
        $this->created($command->realm, $command->name, 'deleted');

        $this->transactions->run(function () use ($command, $permit, $origin): void {
            $this->store->bump($command->realm);
            $this->store->unassignRole($command->realm, $command->name);
            $this->store->saveChanges($command->realm, $this->store->changes($command->realm)->withoutRole($command->name));
            $this->store->deleteRole($command->realm, $command->name);
            $this->record($command->realm, 'yorvoid.role.delete', $permit, $origin, 'role', $command->name);
        });
    }

    /**
     * @throws UnknownRole
     * @throws InvalidRole      When it's "everyone", which nobody is given
     * @throws UnknownPrincipal
     */
    private function assign(AssignRole $command, Permit $permit, Origin $origin): RoleAssignment
    {
        $permit->use($command);
        $this->assignable($command->realm, $command->role);

        return $this->transactions->run(function () use ($command, $permit, $origin): RoleAssignment {
            if (null === $this->principals->get($command->realm, $command->principalId)) {
                throw new UnknownPrincipal($command->realm, $command->principalId);
            }

            $this->store->bump($command->realm);
            $assignment = new RoleAssignment($command->realm, $command->principalId, $command->role, Time::stored($this->clock->now()), $command->scope, null === $command->expiresAt ? null : Time::stored($command->expiresAt));
            $this->store->assign($assignment);
            $this->record($command->realm, 'yorvoid.role.assign', $permit, $origin, 'principal', $command->principalId->uuid->toRfc4122(), self::present([
                'role' => $command->role,
                'scope' => null === $command->scope ? null : (string) $command->scope,
                'expires_at' => $assignment->expiresAt?->format(\DATE_ATOM),
            ]));

            return $assignment;
        });
    }

    /**
     * @return bool Whether the principal had it
     */
    private function unassign(UnassignRole $command, Permit $permit, Origin $origin): bool
    {
        $permit->use($command);

        return $this->transactions->run(function () use ($command, $permit, $origin): bool {
            $this->store->bump($command->realm);
            $had = $this->store->unassign($command->realm, $command->principalId, $command->role, $command->scope);

            if ($had) {
                $this->record($command->realm, 'yorvoid.role.unassign', $permit, $origin, 'principal', $command->principalId->uuid->toRfc4122(), self::present([
                    'role' => $command->role,
                    'scope' => null === $command->scope ? null : (string) $command->scope,
                ]));
            }

            return $had;
        });
    }

    /**
     * @throws UnknownRole|UnknownAbility
     */
    private function grant(GrantAbilities $command, Permit $permit, Origin $origin): AbilityChanges
    {
        $permit->use($command);

        return $this->changeAbilities($command->realm, $command->role, $command->abilities, true, 'yorvoid.ability.grant', $permit, $origin);
    }

    /**
     * @throws UnknownRole|UnknownAbility
     */
    private function revoke(RevokeAbilities $command, Permit $permit, Origin $origin): AbilityChanges
    {
        $permit->use($command);

        return $this->changeAbilities($command->realm, $command->role, $command->abilities, false, 'yorvoid.ability.revoke', $permit, $origin);
    }

    /**
     * Gives the role exactly these abilities, and takes the others it has.
     *
     * @throws UnknownRole|UnknownAbility
     */
    private function set(SetAbilities $command, Permit $permit, Origin $origin): AbilityChanges
    {
        $permit->use($command);
        $this->known($command->realm, $command->role);

        // An ability that isn't declared has no defaults: UnknownAbility, and nothing changes.
        return $this->transactions->run(function () use ($command, $permit, $origin): AbilityChanges {
            $this->store->bump($command->realm);
            $taken = array_diff($this->roles->abilitiesOf($command->realm, $command->role), $command->abilities);
            $changes = $this->store->changes($command->realm);

            foreach ($command->abilities as $ability) {
                $changes = $changes->change($ability, $command->role, true, $this->roles->defaultRolesWith($ability));
            }

            foreach ($taken as $ability) {
                $changes = $changes->change($ability, $command->role, false, $this->roles->defaultRolesWith($ability));
            }

            $this->store->saveChanges($command->realm, $changes);
            $this->record($command->realm, 'yorvoid.ability.set', $permit, $origin, 'role', $command->role, ['abilities' => array_values(array_unique($command->abilities))]);

            return $changes;
        });
    }

    /**
     * @throws UnknownAbility
     */
    private function reset(ResetAbilities $command, Permit $permit, Origin $origin): AbilityChanges
    {
        $permit->use($command);
        $this->declared($command->abilities ?? []);

        return $this->transactions->run(function () use ($command, $permit, $origin): AbilityChanges {
            $this->store->bump($command->realm);
            $changes = $this->store->changes($command->realm)->reset($command->abilities);
            $this->store->saveChanges($command->realm, $changes);
            $this->record($command->realm, 'yorvoid.ability.reset', $permit, $origin, details: ['abilities' => $command->abilities ?? 'all']);

            return $changes;
        });
    }

    /**
     * @param list<string> $abilities
     */
    private function changeAbilities(Realm $realm, string $role, array $abilities, bool $grant, string $action, Permit $permit, Origin $origin): AbilityChanges
    {
        $this->known($realm, $role);

        // An ability that isn't declared has no defaults: UnknownAbility, and nothing changes.
        return $this->transactions->run(function () use ($realm, $role, $abilities, $grant, $action, $permit, $origin): AbilityChanges {
            $this->store->bump($realm);
            $changes = $this->store->changes($realm);

            foreach ($abilities as $ability) {
                $changes = $changes->change($ability, $role, $grant, $this->roles->defaultRolesWith($ability));
            }

            $this->store->saveChanges($realm, $changes);
            $this->record($realm, $action, $permit, $origin, 'role', $role, ['abilities' => array_values(array_unique($abilities))]);

            return $changes;
        });
    }

    /**
     * @throws UnknownRole
     */
    private function known(Realm $realm, string $role): void
    {
        if (!$this->roles->has($realm, $role)) {
            throw new UnknownRole($realm, $role);
        }
    }

    /**
     * @throws UnknownRole
     * @throws InvalidRole
     */
    private function assignable(Realm $realm, string $role): void
    {
        if (Role::EVERYONE === $role) {
            throw new InvalidRole($role, '"everyone" is every actor\'s role: nobody is given it.');
        }

        $this->known($realm, $role);
    }

    /**
     * @throws UnknownRole
     * @throws InvalidRole When it's declared
     */
    private function created(Realm $realm, string $role, string $what): void
    {
        $declared = $this->catalogue->role($role);

        if (null !== $declared || Role::EVERYONE === $role) {
            throw new InvalidRole($role, \sprintf('"%s" is declared by %s: only roles created in the realm are %s.', $role, $declared->owner ?? 'YorvoID', $what));
        }

        $this->known($realm, $role);
    }

    /**
     * @param list<string> $abilities
     *
     * @throws UnknownAbility
     */
    private function declared(array $abilities): void
    {
        foreach ($abilities as $ability) {
            if (!$this->catalogue->has($ability)) {
                throw new UnknownAbility($ability);
            }
        }
    }

    /**
     * @param array<string, string|null> $details
     *
     * @return array<string, string>
     */
    private static function present(array $details): array
    {
        return array_filter($details, static fn (?string $value): bool => null !== $value);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(Realm $realm, string $action, Permit $permit, Origin $origin, ?string $subjectType = null, ?string $subjectId = null, array $details = []): void
    {
        $this->log->record($realm, $action, Outcome::Success, $origin, $permit->actor->principalId, $subjectType, $subjectId, $details);
    }

    /**
     * What it changes is safe only weighed by no-escalation and the step-up, whatever permits it (M2-13).
     */
    public function requiredForbids(): array
    {
        return RolePermit::SAFETY;
    }
}
