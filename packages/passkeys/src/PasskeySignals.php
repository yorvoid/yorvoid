<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

/**
 * What the browser tells the person's passkey provider about their account, through the WebAuthn
 * signal API (ATN-008): which of its passkeys still work here, and the name to show for them. The
 * browser client sends both after signing in and after adding a passkey.
 *
 * @api
 */
final readonly class PasskeySignals
{
    /**
     * @param string       $relyingPartyId The passkeys' relying party
     * @param string       $userId         The account's user handle, base64url
     * @param list<string> $credentialIds  Every passkey the account has, base64url; providers may hide the others
     * @param string       $name           The name the passkeys are shown with
     */
    public function __construct(
        public string $relyingPartyId,
        public string $userId,
        public array $credentialIds,
        public string $name,
    ) {
    }

    /**
     * As PublicKeyCredential.signalAllAcceptedCredentials() and .signalCurrentUserDetails() take them.
     *
     * @return array{rpId: string, userId: string, allAcceptedCredentialIds: list<string>, name: string, displayName: string}
     */
    public function toArray(): array
    {
        return ['rpId' => $this->relyingPartyId, 'userId' => $this->userId, 'allAcceptedCredentialIds' => $this->credentialIds, 'name' => $this->name, 'displayName' => $this->name];
    }
}
