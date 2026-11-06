<?php

declare(strict_types=1);

namespace YorvoID\Notifications;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;

/**
 * "This wasn't me", with the link from a security notification: anyone holding it may, and the handler
 * checks it.
 *
 * @implements Command<\YorvoID\Core\Principal\PrincipalId|null>
 *
 * @api
 */
#[Operation('yorvoid.notification.not_me', 'Report that a security notification wasn\'t one\'s own doing', AuthorizedBy::Authentication, causes: ['yorvoid.session.end_all'])]
final readonly class ReportNotMe implements Command
{
    /**
     * @param string|null $credential The {credential} of the link, if it had one
     */
    public function __construct(
        public Realm $realm,
        #[\SensitiveParameter]
        public Secret $token,
        public ?string $credential = null,
    ) {
    }
}
