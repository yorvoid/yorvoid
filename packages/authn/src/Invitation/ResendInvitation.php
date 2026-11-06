<?php

declare(strict_types=1);

namespace YorvoID\Authn\Invitation;

use YorvoID\Core\Duration;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Sends an invited person a new link; the earlier one stops working.
 *
 * @implements Command<Invitation>
 *
 * @api
 */
#[Operation('yorvoid.invitation.resend', 'Send an invited person a new link', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: 'yorvoid.principals.manage', causes: ['yorvoid.token.issue'])]
final readonly class ResendInvitation implements Command
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public ?Duration $lifetime = null,
    ) {
    }
}
