<?php

declare(strict_types=1);

namespace YorvoID\Notifications;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;

/**
 * Confirms an email address with the link sent to it: anyone holding the link may, and the handler
 * checks it.
 *
 * @implements Command<\YorvoID\Core\Principal\Identifier|null>
 *
 * @api
 */
#[Operation('yorvoid.email_verification.confirm', 'Confirm an email address with the link sent to it', AuthorizedBy::Authentication, causes: ['yorvoid.identifier.verify'])]
final readonly class VerifyEmail implements Command
{
    public function __construct(
        public Realm $realm,
        #[\SensitiveParameter]
        public Secret $token,
    ) {
    }
}
