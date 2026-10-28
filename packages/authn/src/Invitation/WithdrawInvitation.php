<?php

declare(strict_types=1);

namespace YorvoID\Authn\Invitation;

use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Withdraws an invitation: the link stops working, and the invited principal is deleted.
 *
 * @api
 */
#[Operation('yorvoid.invitation.withdraw', 'Withdraw an invitation', ability: 'yorvoid.principals.manage')]
final readonly class WithdrawInvitation
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
    ) {
    }
}
