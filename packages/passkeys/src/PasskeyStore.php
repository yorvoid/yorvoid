<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * @api
 */
interface PasskeyStore
{
    /**
     * Adds a passkey, unless the realm has one with the same WebAuthn ID.
     *
     * @return bool Whether it was added
     */
    public function add(Passkey $passkey): bool;

    public function find(Realm $realm, string $webauthnId): ?Passkey;

    /**
     * @return list<Passkey>
     */
    public function of(Realm $realm, PrincipalId $principalId): array;

    /**
     * Records what the authenticator reported at a sign-in: its counter and backup state.
     */
    public function update(Passkey $passkey): void;

    public function remove(Realm $realm, CredentialId $credentialId): void;
}
