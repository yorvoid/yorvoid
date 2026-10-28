<?php

declare(strict_types=1);

namespace YorvoID\Authn\Invitation;

use YorvoID\Core\Duration;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Sends an invited person a new link; the earlier one stops working.
 *
 * @api
 */
#[Operation('yorvoid.invitation.resend', 'Send an invited person a new link', ability: 'yorvoid.principals.manage')]
final readonly class ResendInvitation
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public ?Duration $lifetime = null,
    ) {
    }
}
