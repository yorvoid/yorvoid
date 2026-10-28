<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A registered passkey: its public key and what the authenticator reported. Its label, last use and
 * status are in the credential registry, under the same ID.
 *
 * @api
 */
final readonly class Passkey
{
    /** @var list<string> */
    public array $transports;

    /**
     * @param string        $webauthnId                  The authenticator's ID for it, raw bytes
     * @param string        $publicKey                   COSE-encoded, raw bytes
     * @param string        $userHandle                  The principal's WebAuthn user handle: random, raw bytes, no personal data
     * @param array<string> $transports                  How the browser can reach the authenticator, e.g. ["internal", "hybrid"]
     * @param bool          $backupEligible              Whether it can sync to other devices
     * @param bool          $backedUp                    Whether it has
     * @param bool          $userVerificationInitialized Whether the authenticator has verified its user with it at least once
     */
    public function __construct(
        public CredentialId $credentialId,
        public Realm $realm,
        public PrincipalId $principalId,
        public string $webauthnId,
        public string $publicKey,
        public string $userHandle,
        public int $signCount,
        array $transports,
        public Uuid $aaguid,
        public bool $backupEligible,
        public bool $backedUp,
        public bool $userVerificationInitialized,
        public string $attestationType,
    ) {
        $this->transports = array_values($transports);
    }

    /**
     * @internal For the WebAuthn library's checks
     */
    public function record(): CredentialRecord
    {
        return new CredentialRecord($this->webauthnId, 'public-key', $this->transports, $this->attestationType, EmptyTrustPath::create(), $this->aaguid, $this->publicKey, $this->userHandle, $this->signCount, null, $this->backupEligible, $this->backedUp, $this->userVerificationInitialized);
    }
}
