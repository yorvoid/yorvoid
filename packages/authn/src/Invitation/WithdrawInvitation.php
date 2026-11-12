<?php

declare(strict_types=1);

namespace YorvoID\Authn\Invitation;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Withdraws an invitation: the link stops working, and the invited principal is deleted.
 *
 * @implements Command<null>
 *
 * @api
 */
#[Operation('yorvoid.invitation.withdraw', 'Withdraw an invitation', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: 'yorvoid.principals.manage', causes: ['yorvoid.token.revoke', 'yorvoid.principal.delete'])]
final readonly class WithdrawInvitation implements Command, ConcernsPrincipal
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
    ) {
    }

    public function principal(): PrincipalId
    {
        return $this->principalId;
    }
}
