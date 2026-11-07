<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\Operator;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\CreateRole;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Policy\Change\ResetAbilities;
use YorvoID\Policy\Change\RevokeAbilities;
use YorvoID\Policy\Change\SetAbilities;
use YorvoID\Policy\Change\UnassignRole;

/**
 * Nobody gives or takes what they don't hold themselves: a role whose abilities they don't all have, a
 * super role unless they have one, an ability they lack. Managing roles doesn't make anyone more than
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
                || $command instanceof GrantAbilities || $command instanceof RevokeAbilities || $command instanceof SetAbilities || $command instanceof ResetAbilities);
    }

    public function evaluate(Request $request): Truth
    {
        $command = $request->command;
        [$role, $abilities] = match (true) {
            $command instanceof AssignRole, $command instanceof UnassignRole => [$command->role, $this->roles->abilitiesOf($request->realm, $command->role)],
            $command instanceof CreateRole && null !== $command->from => [$command->from, $this->roles->abilitiesOf($request->realm, $command->from)],
            $command instanceof GrantAbilities, $command instanceof RevokeAbilities => [null, $command->abilities],
            $command instanceof SetAbilities => [null, [...$command->abilities, ...$this->roles->abilitiesOf($request->realm, $command->role)]],
            $command instanceof ResetAbilities => [null, $command->abilities ?? $this->roles->changes($request->realm)->abilities()],
            default => [null, []],
        };

        if (null !== $role && true === $this->roles->role($request->realm, $role)?->super && !$this->hasSuperRole($request)) {
            return Truth::holds(\sprintf('"%s" is a super role: only someone with one gives it or takes it.', $role));
        }

        $missing = $this->roles->missing($request, array_unique($abilities));

        return [] === $missing
            ? Truth::fails('They hold everything they change.')
            : Truth::holds(\sprintf('They\'d change what they don\'t hold themselves: %s.', implode(', ', $missing)));
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
