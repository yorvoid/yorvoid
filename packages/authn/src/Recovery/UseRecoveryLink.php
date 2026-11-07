<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;

/**
 * Uses a recovery link: anyone holding it may, and the handler checks it.
 *
 * @implements Command<\YorvoID\Authn\Session\StartedSession|null>
 *
 * @api
 */
#[Operation('yorvoid.recovery_link.use', 'Recover access with a recovery link', AuthorizedBy::Authentication, causes: ['yorvoid.session.end_all', 'yorvoid.session.start'])]
final readonly class UseRecoveryLink implements Command
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
