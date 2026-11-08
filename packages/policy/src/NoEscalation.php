<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\Operator;
use YorvoID\Policy\Change\AddGroupMember;
use YorvoID\Policy\Change\AssignGroupRole;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\CreateRole;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Policy\Change\RemoveGroupMember;
use YorvoID\Policy\Change\ResetAbilities;
use YorvoID\Policy\Change\RevokeAbilities;
use YorvoID\Policy\Change\SetAbilities;
use YorvoID\Policy\Change\UnassignGroupRole;
use YorvoID\Policy\Change\UnassignRole;
use YorvoID\Relations\Change\AddRelation;
use YorvoID\Relations\Change\RemoveRelation;
use YorvoID\Relations\Subject;
use YorvoID\Relations\TooDeep;

/**
 * Nobody gives or takes what they don't hold themselves: a role whose abilities they don't all have, a
 * super role unless they have one, an ability they lack, membership of a group with such a role, a
 * relation that gives what they lack on its resource. Managing roles doesn't make anyone more than
 * they are. The host's operator is the final authority, and isn't held to it.
 *
 * @api
 */
final readonly class NoEscalation implements ForbidElement
{
    public function __construct(
        private Roles $roles,
    ) {
    }

    public function name(): string
    {
        return 'yorvoid.policy.no_escalation';
    }

    public function matches(Request $request): bool
    {
        $command = $request->command;

        return !$request->actor instanceof Operator
            && ($command instanceof AssignRole || $command instanceof UnassignRole || ($command instanceof CreateRole && null !== $command->from)
                || $command instanceof GrantAbilities || $command instanceof RevokeAbilities || $command instanceof SetAbilities || $command instanceof ResetAbilities
                || $command instanceof AssignGroupRole || $command instanceof UnassignGroupRole || $command instanceof AddGroupMember || $command instanceof RemoveGroupMember
                || $command instanceof AddRelation || $command instanceof RemoveRelation);
    }

    public function evaluate(Request $request): Truth
    {
        $command = $request->command;

        if ($command instanceof AddGroupMember || $command instanceof RemoveGroupMember) {
            return $this->roles($request, $this->roles->rolesOfGroup($request->realm, $command->group));
        }

        if ($command instanceof AddRelation || $command instanceof RemoveRelation) {
            $schema = $this->roles->relations()?->schema();

            return $this->abilities($request, null === $schema ? [] : $schema->abilitiesGivenBy($command->object->type, $command->relation));
        }

        [$role, $abilities] = match (true) {
            $command instanceof AssignRole, $command instanceof UnassignRole, $command instanceof AssignGroupRole, $command instanceof UnassignGroupRole => [$command->role, $this->roles->abilitiesOf($request->realm, $command->role)],
            $command instanceof CreateRole && null !== $command->from => [$command->from, $this->roles->abilitiesOf($request->realm, $command->from)],
            $command instanceof GrantAbilities, $command instanceof RevokeAbilities => [null, $command->abilities],
            $command instanceof SetAbilities => [null, [...$command->abilities, ...$this->roles->abilitiesOf($request->realm, $command->role)]],
            $command instanceof ResetAbilities => [null, $command->abilities ?? $this->roles->changes($request->realm)->abilities()],
            default => [null, []],
        };

        return null === $role ? $this->abilities($request, $abilities) : $this->roles($request, [$role]);
    }

    /**
     * Each role's abilities they hold, and a super role only if they have one.
     *
     * @param list<string> $roles
     */
    private function roles(Request $request, array $roles): Truth
    {
        $abilities = [];

        foreach ($roles as $role) {
            if (true === $this->roles->role($request->realm, $role)?->super && !$this->hasSuperRole($request)) {
                return Truth::holds(\sprintf('"%s" is a super role: only someone with one gives it or takes it.', $role));
            }

            array_push($abilities, ...$this->roles->abilitiesOf($request->realm, $role));
        }

        return $this->abilities($request, $abilities);
    }

    /**
     * Every ability they hold, through a role or a relation to the request's resource.
     *
     * @param list<string> $abilities
     */
    private function abilities(Request $request, array $abilities): Truth
    {
        $missing = array_filter($this->roles->missing($request, array_unique($abilities)), fn (string $ability): bool => !$this->related($request, $ability));

        return [] === $missing
            ? Truth::fails('They hold everything they change.')
            : Truth::holds(\sprintf('They\'d change what they don\'t hold themselves: %s.', implode(', ', $missing)));
    }

    /**
     * Whether a relation to the request's resource gives them the ability.
     */
    private function related(Request $request, string $ability): bool
    {
        $relations = $this->roles->relations();
        $principal = $request->actor->principalId;

        if (null === $relations || null === $request->resource || null === $principal) {
            return false;
        }

        foreach ($relations->schema()->granting($request->resource->type, $ability) as $relation) {
            if ($relations->check($request->realm, $request->resource, $relation, Subject::principal($principal)) ?? throw new TooDeep(\sprintf('The relations to %s', $request->resource))) {
                return true;
            }
        }

        return false;
    }

    private function hasSuperRole(Request $request): bool
    {
        foreach ($this->roles->rolesOf($request) as $role) {
            if (true === $this->roles->role($request->realm, $role)?->super) {
                return true;
            }
        }

        return false;
    }
}
