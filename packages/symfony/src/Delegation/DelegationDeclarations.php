<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Delegation;

use YorvoID\Core\Authorization\Delegable;
use YorvoID\Delegation\DelegationAbilities;
use YorvoID\Policy\Ability;
use YorvoID\Policy\PolicyDeclarations;

/**
 * The delegation package's abilities, declared because it's installed: issuing and revoking one's own
 * delegations, which everyone may, and revoking anyone's, which nobody may by default. None of them is
 * delegable: what's passed on again is a delegation's own business (its re-delegable actions).
 *
 * @internal
 */
final readonly class DelegationDeclarations implements PolicyDeclarations
{
    public function abilities(): iterable
    {
        yield new Ability(DelegationAbilities::ISSUE, DelegationAbilities::NAMES[DelegationAbilities::ISSUE], 'yorvoid', ['everyone'], 'Delegation', delegable: Delegable::Nobody);
        yield new Ability(DelegationAbilities::REVOKE, DelegationAbilities::NAMES[DelegationAbilities::REVOKE], 'yorvoid', ['everyone'], 'Delegation', delegable: Delegable::Nobody);
        yield new Ability(DelegationAbilities::MANAGE, DelegationAbilities::NAMES[DelegationAbilities::MANAGE], 'yorvoid', [], 'Delegation', strict: true, delegable: Delegable::Nobody);
    }

    public function roles(): iterable
    {
        return [];
    }
}
