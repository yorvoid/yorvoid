<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * One operation to sign (INT-001): what to do, with which parameters, for which verifier, once, until
 * when. Its canonical form (RFC 8785) is what a passkey signs, through the challenge.
 *
 * @api
 */
final readonly class Intent
{
    /** Separates intents' challenges from every other use of SHA-256 (INT-002) */
    public const string DOMAIN = "yorvoid-intent-v1\0";

    /**
     * @param string               $action     The operation, e.g. "quire.site.remove"
     * @param array<string, mixed> $parameters Its parameters, e.g. ["site" => "jane"]
     * @param string               $audience   The verifier it's for, so a signature for one verifier works at no other
     * @param string               $nonce      Issued by the verifier; works once
     */
    public function __construct(
        public string $action,
        public array $parameters,
        public string $audience,
        public string $nonce,
        public \DateTimeImmutable $issuedAt,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * The canonical form: what is signed.
     */
    public function canonical(): string
    {
        return CanonicalJson::encode([
            'action' => $this->action,
            'audience' => $this->audience,
            'expires_at' => $this->expiresAt->getTimestamp(),
            'issued_at' => $this->issuedAt->getTimestamp(),
            'nonce' => $this->nonce,
            'parameters' => (object) $this->parameters,
        ]);
    }

    /**
     * The WebAuthn challenge: SHA-256 of the canonical form, domain-separated (INT-002).
     *
     * @return string 32 bytes
     */
    public function challenge(): string
    {
        return hash('sha256', self::DOMAIN.$this->canonical(), true);
    }
}
