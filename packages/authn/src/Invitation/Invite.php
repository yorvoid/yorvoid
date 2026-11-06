<?php

declare(strict_types=1);

namespace YorvoID\Authn\Invitation;

use YorvoID\Core\Duration;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * Invites a person by email (IDN-020): they get a link, and choose how they sign in when they open it.
 *
 * @implements Command<Invitation>
 *
 * @api
 */
#[Operation('yorvoid.invitation.send', 'Invite a person by email', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: 'yorvoid.principals.manage', inverse: 'yorvoid.invitation.withdraw', causes: ['yorvoid.principal.create', 'yorvoid.token.issue'])]
final readonly class Invite implements Command
{
    /**
     * @param Duration|null $lifetime How long the link works; 7 days if not given (ATN-032)
     */
    public function __construct(
        public Realm $realm,
        public string $email,
        public ?string $username = null,
        public ?Duration $lifetime = null,
    ) {
    }
}
