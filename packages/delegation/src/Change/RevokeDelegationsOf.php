<?php

declare(strict_types=1);

namespace YorvoID\Delegation\Change;

use YorvoID\Core\Authorization\ChangesAuthority;
use YorvoID\Core\Authorization\RevokesDelegation;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Delegation\DelegationAbilities;

/**
 * Revokes every active delegation issued by or to a principal, in one go (AGT-042): what was passed on
 * from them stops at its next use.
 *
 * @implements ChangesAuthority<int>
 *
 * @api
 *
 * @experimental
 */
#[Operation('yorvoid.delegation.revoke_all', 'Revoke every delegation of a principal', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: DelegationAbilities::MANAGE)]
final readonly class RevokeDelegationsOf implements ChangesAuthority, ConcernsPrincipal, RevokesDelegation
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public string $why,
    ) {
    }

    public function principal(): PrincipalId
    {
        return $this->principalId;
    }
}
