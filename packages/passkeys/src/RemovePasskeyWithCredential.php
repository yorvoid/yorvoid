<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

use YorvoID\Authn\Credential\CredentialKind;
use YorvoID\Authn\Credential\Event\CredentialRemoving;

/**
 * A listener for CredentialRemoving: when a passkey is removed from the credential registry, its public
 * key goes too, in the same transaction. (Without it, the passkey can't sign in anyway: sign-in needs
 * its registry entry.).
 *
 * @api
 */
final readonly class RemovePasskeyWithCredential
{
    public function __construct(
        private PasskeyStore $store,
    ) {
    }

    public function __invoke(CredentialRemoving $event): void
    {
        if (CredentialKind::Passkey === $event->credential->kind) {
            $this->store->remove($event->credential->realm, $event->credential->id);
        }
    }
}
