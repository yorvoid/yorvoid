<?php

declare(strict_types=1);

namespace YorvoID\Core\Key;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Secret;

/**
 * An Ed25519 key (libsodium; RFC 8032) with its ID and status. Signatures name the key's ID, so keys can
 * be rotated without breaking what was signed before (OPS-001, D-13). Without its private part, a key
 * only verifies: that's what a separate verifier holds.
 *
 * @api
 */
final readonly class SigningKey
{
    public const string ALGORITHM = 'EdDSA';

    private const string ID = '/^[A-Za-z0-9._-]{1,64}$/D';

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

        if (\SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($publicKey)) {
            throw new IntegratorError(\sprintf('The public part of the key "%s" isn\'t an Ed25519 public key.', $id), 'Use the 32-byte public key, or generate the key with YorvoID.', 'concepts/keys.md');
        }

        $this->publicKey = $publicKey;

        if (null !== $seed) {
            if (\SODIUM_CRYPTO_SIGN_SEEDBYTES !== \strlen($seed->reveal())) {
                throw new IntegratorError(\sprintf('The private part of the key "%s" isn\'t an Ed25519 seed.', $id), 'Use the 32-byte seed, or generate the key with YorvoID.', 'concepts/keys.md');
            }

            if (!hash_equals(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed->reveal())), $publicKey)) {
                throw new IntegratorError(\sprintf('The public and private parts of the key "%s" don\'t belong together.', $id), 'Check the key\'s "x" and "d" values; they must come from the same generated key.', 'concepts/keys.md');
            }
        }

        if (KeyStatus::Active === $status && null === $seed) {
            throw new IntegratorError(\sprintf('The key "%s" is active but has no private part.', $id), 'An active key signs, so it needs its private part ("d"); a key without one can only be verify-only.', 'concepts/keys.md');
        }
    }

    public static function generate(string $id, KeyStatus $status = KeyStatus::Active): self
    {
        $seed = Secret::random(\SODIUM_CRYPTO_SIGN_SEEDBYTES);

        return new self($id, $status, sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed->reveal())), $seed);
    }

    /**
     * @return string The 64-byte signature
     *
     * @infection-ignore-all Wiping the expanded private key from memory afterwards can't be observed by a test.
     */
    public function sign(string $message): string
    {
        if (KeyStatus::Active !== $this->status || null === $this->seed) {
            throw new IntegratorError(\sprintf('The key "%s" isn\'t active, so it doesn\'t sign.', $this->id), 'Sign with KeyRing::signer(), the ring\'s active key.', 'concepts/keys.md');
        }

        $secretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($this->seed->reveal()));

        try {
            return sodium_crypto_sign_detached($message, $secretKey);
        } finally {
            sodium_memzero($secretKey);
        }
    }

    public function verify(string $message, string $signature): bool
    {
        return \SODIUM_CRYPTO_SIGN_BYTES === \strlen($signature) && sodium_crypto_sign_verify_detached($signature, $message, $this->publicKey);
    }

    /**
     * The same key without its private part, e.g. to give to a verifier.
     */
    public function withoutPrivatePart(): self
    {
        return new self($this->id, KeyStatus::VerifyOnly, $this->publicKey);
    }
}
