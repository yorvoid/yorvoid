<?php

declare(strict_types=1);

namespace YorvoID\Authn\Setup;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;

/**
 * Claims a fresh realm with its setup code, creating its owner and signing them in: anyone holding the
 * code may, and the handler checks it and counts the attempts.
 *
 * @implements Command<\YorvoID\Authn\Session\StartedSession>
 *
 * @api
 */
#[Operation('yorvoid.setup.claim', 'Claim a fresh installation with its setup code', AuthorizedBy::Authentication, causes: ['yorvoid.principal.create', 'yorvoid.session.start'])]
final readonly class ClaimSetup implements Command
{
    /**
     * @param CreatePrincipal $owner        The owner to create: active, in this realm
     * @param string|null     $device       As for StartSession
     * @param string|null     $address      The client's IP address; pass it, so attempts from one address are limited
     * @param Secret|null     $deviceCookie The device cookie the browser sent, if any; the session's result says which to set
     */
    public function __construct(
        public Realm $realm,
        #[\SensitiveParameter]
        public Secret $code,
        public CreatePrincipal $owner,
        public ?string $device = null,
        public ?string $address = null,
        #[\SensitiveParameter]
        public ?Secret $deviceCookie = null,
    ) {
    }
}
