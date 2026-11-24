<?php

declare(strict_types=1);

namespace YorvoID\Delegation\Change;

use YorvoID\Core\Authorization\ChangesAuthority;
use YorvoID\Core\Authorization\RevokesDelegation;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Delegation\DelegationAbilities;

/**
 * Revokes a delegation: by its delegator, or one above it in its chain, or the operator. Everything
 * passed on from it stops at its next use.
 *
 * @implements ChangesAuthority<bool>
 *
 * @api
 *
 * @experimental
 */
#[Operation('yorvoid.delegation.revoke', 'Revoke a delegation', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: DelegationAbilities::REVOKE)]
final readonly class RevokeDelegation implements ChangesAuthority, RevokesDelegation
{
    public function __construct(
        public Realm $realm,
        public DelegationId $delegation,
        public string $why,
    ) {
    }
}
