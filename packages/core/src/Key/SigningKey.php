<?php

declare(strict_types=1);

namespace YorvoID\Core\Key;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Secret;

/**
 * An Ed25519 key (RFC 8032, through OpenSSL, so that a FIPS provider signs when one is configured:
 * CRY-01) with its ID and status. Signatures name the key's ID, so keys can
 * be rotated without breaking what was signed before (OPS-001, D-13). Without its private part, a key
 * only verifies: that's what a separate verifier holds.
 *
 * @api
 */
final readonly class SigningKey
{
    public const string ALGORITHM = 'EdDSA';

    private const string ID = '/^[A-Za-z0-9._-]{1,64}$/D';

    /** The lengths of an Ed25519 public key, its seed and a signature (RFC 8032) */
    private const int PUBLIC_KEY = 32;

    private const int SEED = 32;

    private const int SIGNATURE = 64;

    /** OpenSSL's "no digest": EdDSA hashes the message itself */
    private const int NO_DIGEST = 0;

    /** @var non-empty-string */
    public string $publicKey;

    public function __construct(
        public string $id,
        public KeyStatus $status,
        string $publicKey,
        public ?Secret $seed = null,
    ) {
        if (1 !== preg_match(self::ID, $id)) {
            throw new IntegratorError(\sprintf('The key ID "%s" is invalid.', $id), 'Use 1 to 64 letters, digits, dots, underscores or hyphens, e.g. "2026-10".', 'concepts/keys.md');
        }

        if (self::PUBLIC_KEY !== \strlen($publicKey)) {
            throw new IntegratorError(\sprintf('The public part of the key "%s" isn\'t an Ed25519 public key.', $id), 'Use the 32-byte public key, or generate the key with YorvoID.', 'concepts/keys.md');
        }

        $this->publicKey = $publicKey;

        if (null !== $seed) {
            if (self::SEED !== \strlen($seed->reveal())) {
                throw new IntegratorError(\sprintf('The private part of the key "%s" isn\'t an Ed25519 seed.', $id), 'Use the 32-byte seed, or generate the key with YorvoID.', 'concepts/keys.md');
            }

            if (!hash_equals(self::publicKeyOf($seed), $publicKey)) {
                throw new IntegratorError(\sprintf('The public and private parts of the key "%s" don\'t belong together.', $id), 'Check the key\'s "x" and "d" values; they must come from the same generated key.', 'concepts/keys.md');
            }
        }

        if (KeyStatus::Active === $status && null === $seed) {
            throw new IntegratorError(\sprintf('The key "%s" is active but has no private part.', $id), 'An active key signs, so it needs its private part ("d"); a key without one can only be verify-only.', 'concepts/keys.md');
        }
    }

    public static function generate(string $id, KeyStatus $status = KeyStatus::Active): self
    {
        $seed = Secret::random(self::SEED);

        return new self($id, $status, self::publicKeyOf($seed), $seed);
    }

    /**
     * @return string The 64-byte signature
     */
    public function sign(string $message): string
    {
        if (KeyStatus::Active !== $this->status || null === $this->seed) {
            throw new IntegratorError(\sprintf('The key "%s" isn\'t active, so it doesn\'t sign.', $this->id), 'Sign with KeyRing::signer(), the ring\'s active key.', 'concepts/keys.md');
        }

        if (!openssl_sign($message, $signature, self::privateKey($this->seed), self::NO_DIGEST) || !\is_string($signature) || self::SIGNATURE !== \strlen($signature)) {
            throw new \RuntimeException(\sprintf('OpenSSL couldn\'t sign with the key "%s": %s', $this->id, self::error()));
        }

        return $signature;
    }

    public function verify(string $message, string $signature): bool
    {
        // A signature of another length than 64 bytes OpenSSL refuses itself.
        $key = openssl_pkey_new(['ed25519' => ['pub_key' => $this->publicKey]]);

        if (false === $key) {
            throw new \RuntimeException(\sprintf('OpenSSL couldn\'t read the key "%s": %s', $this->id, self::error()));
        }

        return 1 === openssl_verify($message, $signature, $key, self::NO_DIGEST);
    }

    /**
     * The same key without its private part, e.g. to give to a verifier.
     */
    public function withoutPrivatePart(): self
    {
        return new self($this->id, KeyStatus::VerifyOnly, $this->publicKey);
    }

    private static function privateKey(Secret $seed): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['ed25519' => ['priv_key' => $seed->reveal()]]);

        return false === $key ? throw new \RuntimeException(\sprintf('OpenSSL couldn\'t make an Ed25519 key: %s', self::error())) : $key;
    }

    private static function publicKeyOf(Secret $seed): string
    {
        $details = openssl_pkey_get_details(self::privateKey($seed));
        $public = \is_array($details) && \is_array($details['ed25519'] ?? null) ? ($details['ed25519']['pub_key'] ?? null) : null;

        return \is_string($public) ? $public : throw new \RuntimeException('OpenSSL gave no Ed25519 public key.');
    }

    /**
     * Why OpenSSL failed, as far as it says.
     */
    private static function error(): string
    {
        $error = openssl_error_string();

        return false === $error ? 'Ed25519 isn\'t available' : $error;
    }
}
