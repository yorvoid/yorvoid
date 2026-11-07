<?php

declare(strict_types=1);

namespace YorvoID\Authn\Invitation;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;

/**
 * Accepts an invitation with the link's token, and signs the invitee in: anyone holding it may, and the
 * handler checks it.
 *
 * @implements Command<\YorvoID\Authn\Session\StartedSession|null>
 *
 * @api
 */
#[Operation('yorvoid.invitation.accept', 'Accept an invitation', AuthorizedBy::Authentication, causes: ['yorvoid.principal.activate', 'yorvoid.identifier.verify', 'yorvoid.session.start'])]
final readonly class AcceptInvitation implements Command
{
    /**
     * @param string|null $device       As for StartSession
     * @param string|null $address      As for StartSession
     * @param Secret|null $deviceCookie The device cookie the browser sent, if any; the session's result says which to set
     */
    public function __construct(
        public Realm $realm,
        #[\SensitiveParameter]
        public Secret $token,
        public ?string $device = null,
        public ?string $address = null,
        #[\SensitiveParameter]
        public ?Secret $deviceCookie = null,
    ) {
    }
}
