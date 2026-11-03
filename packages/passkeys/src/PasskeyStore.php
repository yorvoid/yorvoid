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
     * Records what the authenticator reported at a sign-in: its counter, whether it's backed up, and
     * whether user verification is set up; backup eligibility stays the registration's. Only over a
     * lower counter, or when the counter is 0 (synced passkeys keep none), in one step: of two sign-ins
     * with the same counter at the same time, one is recorded.
     *
     * @return bool Whether it was recorded
     */
    public function update(Passkey $passkey): bool;

    public function remove(Realm $realm, CredentialId $credentialId): void;
}
