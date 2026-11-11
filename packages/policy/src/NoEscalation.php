<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Operation\Operator;
use YorvoID\Policy\Attribute\SetAttribute;
use YorvoID\Policy\Change\AddGroupMember;
use YorvoID\Policy\Change\AssignGroupRole;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\CreateRole;
use YorvoID\Policy\Change\DeleteGroup;
use YorvoID\Policy\Change\DeleteRole;
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
 * Nobody gives or takes what they don't hold themselves (M2-13): each change is weighed by what it
 * gives and takes, to anyone, directly or through what follows from it (Effects), and refused when they
 * couldn't give or take that directly: a role whose abilities they don't all have, a super role unless
 * they have one, an ability they lack. Nobody sets their own security attributes either: what they
 * decide is the application's rules'.
 *
 * Managing roles doesn't make anyone more than they are. The host's operator is the final authority,
 * and isn't held to it.
 *
 * @api
 */
final readonly class NoEscalation implements ForbidElement
{
    public const string NAME = 'yorvoid.policy.no_escalation';

    public function __construct(
        private Roles $roles,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function matches(Request $request): bool
    {
        $command = $request->command;

        return !$request->actor instanceof Operator
            && ($command instanceof AssignRole || $command instanceof UnassignRole || ($command instanceof CreateRole && null !== $command->from) || $command instanceof DeleteRole
                || $command instanceof GrantAbilities || $command instanceof RevokeAbilities || $command instanceof SetAbilities || $command instanceof ResetAbilities
                || $command instanceof AssignGroupRole || $command instanceof UnassignGroupRole || $command instanceof AddGroupMember || $command instanceof RemoveGroupMember || $command instanceof DeleteGroup
                || $command instanceof AddRelation || $command instanceof RemoveRelation || $command instanceof SetAttribute);
    }

    public function evaluate(Request $request): Truth
    {
        $command = $request->command;

        if ($command instanceof SetAttribute) {
            return null !== $request->actor->principalId && $request->actor->principalId->equals($command->principalId)
                ? Truth::holds('Nobody sets their own security attributes.')
                : Truth::fails('They set someone else\'s.');
        }

        $effect = Effects::of($this->roles)->on($request);

        foreach ([...$effect->gives, ...$effect->takes] as $part) {
            $truth = $this->roles(null === $part->on ? $request : $request->on($part->on), $part->roles, $part->abilities, $part->byRelation);

            if (TruthValue::Fails !== $truth->value) {
                return $truth;
            }
        }

        return Truth::fails('They hold everything they change.');
    }

    /**
     * Each role's abilities they hold, and a super role only if they have one; and the abilities.
     *
     * @param list<string> $roles
     * @param list<string> $abilities
     * @param bool         $byRelation Whether a relation to the request's resource holds an ability, or only a role does
     */
    private function roles(Request $request, array $roles, array $abilities, bool $byRelation): Truth
    {
        foreach ($roles as $role) {
            if (true === $this->roles->role($request->realm, $role)?->super && !$this->hasSuperRole($request)) {
                return Truth::holds(\sprintf('"%s" is a super role: only someone with one gives it or takes it.', $role));
            }

            array_push($abilities, ...$this->roles->abilitiesOf($request->realm, $role));
        }

        return $this->abilities($request, $abilities, $byRelation);
    }

    /**
     * Every ability they hold, through a role or, unless only a role will do, a relation to the
     * request's resource.
     *
     * @param list<string> $abilities
     */
    private function abilities(Request $request, array $abilities, bool $byRelation): Truth
    {
        $missing = array_filter($this->roles->missing($request, array_unique($abilities)), fn (string $ability): bool => !$byRelation || !$this->related($request, $ability));

        if ([] === $missing) {
            return Truth::fails('They hold everything they change.');
        }

        $where = null === $request->resource ? '' : \sprintf(' on %s', $request->resource);

        return Truth::holds(\sprintf('They\'d change what they don\'t hold themselves%s: %s.', $where, implode(', ', $missing)));
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
