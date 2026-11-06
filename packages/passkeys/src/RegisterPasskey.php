<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\SelfOperation;
use YorvoID\Core\Realm;

/**
 * Finishes adding a passkey to one's own account, with the browser's answer to the ceremony that
 * Passkeys::beginRegistration() began.
 *
 * @implements SelfOperation<\YorvoID\Authn\Credential\Credential>
 *
 * @api
 */
#[Operation('yorvoid.passkey.register', 'Add a passkey to one\'s account', AuthorizedBy::Self, causes: ['yorvoid.credential.add'])]
final readonly class RegisterPasskey implements SelfOperation
{
    /**
     * @param string $response The browser's PublicKeyCredential, as JSON
     * @param string $label    What its owner calls it, e.g. "MacBook"
     */
    public function __construct(
        public Realm $realm,
        public string $ceremonyId,
        public string $response,
        public string $label,
    ) {
    }

    public function concerns(Actor $actor): bool
    {
        // The ceremony is the session's own: the handler takes it for that session only.
        return null !== $actor->principalId;
    }
}
