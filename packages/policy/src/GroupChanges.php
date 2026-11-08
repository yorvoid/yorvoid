<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Core\Time;
use YorvoID\Policy\Change\AddGroupMember;
use YorvoID\Policy\Change\AssignGroupRole;
use YorvoID\Policy\Change\CreateGroup;
use YorvoID\Policy\Change\DeleteGroup;
use YorvoID\Policy\Change\RemoveGroupMember;
use YorvoID\Policy\Change\UnassignGroupRole;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\RelationTuple;

/**
 * Changes a realm's groups (IDN-056): their members, principals or other groups, and the roles given to
 * them. Each change increments the policy version, in one transaction with its security log entry.
 *
 * @api
 */
final readonly class GroupChanges implements OperationHandler
{
    private Roles $roles;

    public function __construct(
        PolicyCatalogue $catalogue,
        private PolicyStore $store,
        private RelationStore $relations,
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
            CreateGroup::class => $this->create(...),
            DeleteGroup::class => $this->delete(...),
            AddGroupMember::class => $this->addMember(...),
            RemoveGroupMember::class => $this->removeMember(...),
            AssignGroupRole::class => $this->assign(...),
            UnassignGroupRole::class => $this->unassign(...),
        ];
    }

    /**
     * @throws InvalidRole When the name or label isn't valid (a group's are a role's)
     */
    private function create(CreateGroup $command, Permit $permit, Origin $origin): void
    {
        $permit->use($command);
        $group = new Role($command->name, $command->label);

        $this->transactions->run(function () use ($command, $group, $permit, $origin): void {
            $this->store->bump($command->realm);
            $this->store->saveGroup($command->realm, $group->name, $group->label);
            $this->record($command->realm, 'yorvoid.group.create', $permit, $origin, $group->name, ['label' => $group->label]);
        });
    }

    /**
     * @throws UnknownGroup
     */
    private function delete(DeleteGroup $command, Permit $permit, Origin $origin): void
    {
        $permit->use($command);
        $this->known($command->realm, $command->name);

        $this->transactions->run(function () use ($command, $permit, $origin): void {
            $this->store->bump($command->realm);
            $this->relations->forget($command->realm, GroupRelations::group($command->name));
            $this->store->deleteGroup($command->realm, $command->name);
            $this->record($command->realm, 'yorvoid.group.delete', $permit, $origin, $command->name);
        });
    }

    /**
     * @throws UnknownGroup|UnknownPrincipal
     * @throws InvalidRole                   When the member is neither a principal nor a group's members, or is the group
     */
    private function addMember(AddGroupMember $command, Permit $permit, Origin $origin): void
    {
        $permit->use($command);
        $this->known($command->realm, $command->group);
        $member = $command->member;

        if ('principal' === $member->resource->type && null === $member->relation) {
            $id = PrincipalId::fromString($member->resource->id);

            if (null === $this->principals->get($command->realm, $id)) {
                throw new UnknownPrincipal($command->realm, $id);
            }
        } elseif (GroupRelations::TYPE === $member->resource->type && GroupRelations::MEMBER === $member->relation) {
            $this->known($command->realm, $member->resource->id);

            if ($member->resource->id === $command->group) {
                throw new InvalidRole($command->group, \sprintf('The group "%s" can\'t be within itself.', $command->group));
            }
        } else {
            throw new InvalidRole($command->group, \sprintf('A group\'s member is a principal or another group\'s members, not %s.', $member));
        }

        $this->transactions->run(function () use ($command, $permit, $origin): void {
            $this->store->bump($command->realm);
            $this->relations->add(new RelationTuple($command->realm, GroupRelations::group($command->group), GroupRelations::MEMBER, $command->member));
            $this->record($command->realm, 'yorvoid.group.add_member', $permit, $origin, $command->group, ['member' => (string) $command->member]);
        });
    }

    /**
     * @return bool Whether it was a member
     */
    private function removeMember(RemoveGroupMember $command, Permit $permit, Origin $origin): bool
    {
        $permit->use($command);

        return $this->transactions->run(function () use ($command, $permit, $origin): bool {
            $this->store->bump($command->realm);
            $was = $this->relations->remove(new RelationTuple($command->realm, GroupRelations::group($command->group), GroupRelations::MEMBER, $command->member));

            if ($was) {
                $this->record($command->realm, 'yorvoid.group.remove_member', $permit, $origin, $command->group, ['member' => (string) $command->member]);
            }

            return $was;
        });
    }

    /**
     * @throws UnknownGroup|UnknownRole
     * @throws InvalidRole              When it's "everyone"
     */
    private function assign(AssignGroupRole $command, Permit $permit, Origin $origin): GroupRoleAssignment
    {
        $permit->use($command);
        $this->known($command->realm, $command->group);

        if (Role::EVERYONE === $command->role) {
            throw new InvalidRole($command->role, '"everyone" is every actor\'s role: nobody is given it.');
        }

        if (!$this->roles->has($command->realm, $command->role)) {
            throw new UnknownRole($command->realm, $command->role);
        }

        return $this->transactions->run(function () use ($command, $permit, $origin): GroupRoleAssignment {
            $this->store->bump($command->realm);
            $assignment = new GroupRoleAssignment($command->realm, $command->group, $command->role, Time::stored($this->clock->now()), $command->scope, null === $command->expiresAt ? null : Time::stored($command->expiresAt));
            $this->store->assignGroup($assignment);
            $this->record($command->realm, 'yorvoid.group.assign_role', $permit, $origin, $command->group, array_filter([
                'role' => $command->role,
                'scope' => null === $command->scope ? null : (string) $command->scope,
                'expires_at' => $assignment->expiresAt?->format(\DATE_ATOM),
            ], static fn (?string $value): bool => null !== $value));

            return $assignment;
        });
    }

    /**
     * @return bool Whether it had it
     */
    private function unassign(UnassignGroupRole $command, Permit $permit, Origin $origin): bool
    {
        $permit->use($command);

        return $this->transactions->run(function () use ($command, $permit, $origin): bool {
            $this->store->bump($command->realm);
            $had = $this->store->unassignGroup($command->realm, $command->group, $command->role, $command->scope);

            if ($had) {
                $this->record($command->realm, 'yorvoid.group.unassign_role', $permit, $origin, $command->group, array_filter([
                    'role' => $command->role,
                    'scope' => null === $command->scope ? null : (string) $command->scope,
                ], static fn (?string $value): bool => null !== $value));
            }

            return $had;
        });
    }

    /**
     * @throws UnknownGroup
     */
    private function known(Realm $realm, string $group): void
    {
        if (!isset($this->store->groups($realm)[$group])) {
            throw new UnknownGroup($realm, $group);
        }
    }

    /**
     * @param array<string, string> $details
     */
    private function record(Realm $realm, string $action, Permit $permit, Origin $origin, string $group, array $details = []): void
    {
        $this->log->record($realm, $action, Outcome::Success, $origin, $permit->actor->principalId, 'group', $group, $details);
    }
}
