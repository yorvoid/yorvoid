<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * A passkey registration the verifier started, at its signing origin, and waits for the browser's answer
 * to: the new key's "add key" intent comes from what the authenticator answers, not from anyone's word.
 *
 * @api
 */
final readonly class PendingRegistration
{
    /**
     * @param string $options   The WebAuthn creation options, as JSON, for navigator.credentials.create()
     * @param string $label     What the owner calls the key, e.g. "Ana's YubiKey"
     * @param bool   $bootstrap Whether it's for the bootstrap secret, rather than signed by a key the verifier has
     */
    public function __construct(
        public string $id,
        public string $options,
        public string $label,
        public bool $bootstrap,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
