<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;

/**
 * Finishes signing in with a passkey, with the browser's answer to the ceremony that
 * Passkeys::beginSignIn() began: anyone may try, and the handler checks the answer.
 *
 * @implements Command<\YorvoID\Authn\Session\StartedSession>
 *
 * @api
 */
#[Operation('yorvoid.passkey.sign_in', 'Sign in with a passkey', AuthorizedBy::Authentication, causes: ['yorvoid.session.start'])]
final readonly class SignInWithPasskey implements Command
{
    /**
     * @param string      $response     The browser's PublicKeyCredential, as JSON
     * @param string|null $device       As for StartSession
     * @param string|null $address      As for StartSession; pass it, so attempts from one address are limited
     * @param Secret|null $deviceCookie The device cookie the browser sent, if any; the session's result says which to set
     */
    public function __construct(
        public Realm $realm,
        public string $ceremonyId,
        public string $response,
        public ?string $device = null,
        public ?string $address = null,
        #[\SensitiveParameter]
        public ?Secret $deviceCookie = null,
    ) {
    }
}
