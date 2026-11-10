<?php

declare(strict_types=1);

namespace YorvoID\Core\Key;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Secret;

/**
 * A signing key with its ID, status and algorithm: Ed25519 (RFC 8032), or ECDSA on P-256 (ES256) for a
 * FIPS provider without EdDSA. It signs through OpenSSL, so that a FIPS provider signs when one is
 * configured (CRY-01). Signatures name the key's ID, so keys can be rotated without breaking what was
 * signed before (OPS-001, D-13), and the key named decides the algorithm. Without its private part, a
 * key only verifies: that's what a separate verifier holds.
 *
 * @api
 */
final readonly class SigningKey
{
    /** Ed25519's name, as in JSON Web Keys */
    public const string ALGORITHM = 'EdDSA';

    private const string ID = '/^[A-Za-z0-9._-]{1,64}$/D';

    /** Every signature is 64 bytes: Ed25519's, or ES256's r and s of 32 bytes each (RFC 7518 §3.4) */
    private const int SIGNATURE = 64;

    /** OpenSSL's "no digest": EdDSA hashes the message itself */
    private const int NO_DIGEST = 0;

    /** @var non-empty-string Ed25519: the 32-byte key; ES256: the point's x and y, 32 bytes each */
    public string $publicKey;

    /**
     * @param string      $publicKey Ed25519: the 32-byte public key; ES256: x and y, 32 bytes each
     * @param Secret|null $seed      The private part: Ed25519's 32-byte seed, or ES256's 32-byte scalar d
     */
    public function __construct(
        public string $id,
        public KeyStatus $status,
        string $publicKey,
        public ?Secret $seed = null,
        public KeyAlgorithm $algorithm = KeyAlgorithm::EdDSA,
    ) {
        if (1 !== preg_match(self::ID, $id)) {
            throw new IntegratorError(\sprintf('The key ID "%s" is invalid.', $id), 'Use 1 to 64 letters, digits, dots, underscores or hyphens, e.g. "2026-10".', 'concepts/keys.md');
        }

        [$length, $what] = match ($algorithm) {
            KeyAlgorithm::EdDSA => [32, 'an Ed25519 public key'],
            KeyAlgorithm::ES256 => [64, 'a P-256 public key'],
        };

        if ($length !== \strlen($publicKey)) {
            throw new IntegratorError(\sprintf('The public part of the key "%s" isn\'t %s.', $id, $what), 'Use the public key as YorvoID writes it, or generate the key with YorvoID.', 'concepts/keys.md');
        }

        $this->publicKey = $publicKey;

        if (null !== $seed) {
            if (32 !== \strlen($seed->reveal())) {
                throw new IntegratorError(\sprintf('The private part of the key "%s" isn\'t %s.', $id, KeyAlgorithm::EdDSA === $algorithm ? 'an Ed25519 seed' : 'a P-256 private key'), 'Use the 32-byte private part, or generate the key with YorvoID.', 'concepts/keys.md');
            }

            try {
                $derived = self::publicKeyOf($seed, $algorithm);
            } catch (\RuntimeException $e) {
                // This OpenSSL can't use the algorithm, e.g. Ed25519 under a FIPS provider without EdDSA:
                // a verify-only key is kept, for where it can be checked; one that signs can't be.
                if (KeyStatus::Active === $status) {
                    throw new IntegratorError(\sprintf('The key "%s" is active, but this OpenSSL can\'t sign with %s: %s', $id, $algorithm->value, $e->getMessage()), 'Under a FIPS provider without EdDSA, rotate to an ES256 key in the standard crypto mode first (yorvoid:keys:rotate --algorithm=ES256, twice) and anchor every realm (yorvoid:log:anchor --all).', 'concepts/keys.md#moving-to-es256');
                }

                $derived = $publicKey;
            }

            if (!hash_equals($derived, $publicKey)) {
                throw new IntegratorError(\sprintf('The public and private parts of the key "%s" don\'t belong together.', $id), 'Check the key\'s "x", "y" and "d" values; they must come from the same generated key.', 'concepts/keys.md');
            }
        }

        if (KeyStatus::Active === $status && null === $seed) {
            throw new IntegratorError(\sprintf('The key "%s" is active but has no private part.', $id), 'An active key signs, so it needs its private part ("d"); a key without one can only be verify-only.', 'concepts/keys.md');
        }
    }

    public static function generate(string $id, KeyStatus $status = KeyStatus::Active, KeyAlgorithm $algorithm = KeyAlgorithm::EdDSA): self
    {
        if (KeyAlgorithm::EdDSA === $algorithm) {
            $seed = Secret::random(32);

            return new self($id, $status, self::publicKeyOf($seed, $algorithm), $seed, $algorithm);
        }

        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        [$x, $y, $d] = self::point(false === $key ? throw self::failed('make a P-256 key') : $key);

        return new self($id, $status, $x.$y, Secret::fromString((string) $d), $algorithm);
    }

    /**
     * @return string The 64-byte signature
     */
    public function sign(string $message): string
    {
        if (KeyStatus::Active !== $this->status || null === $this->seed) {
            throw new IntegratorError(\sprintf('The key "%s" isn\'t active, so it doesn\'t sign.', $this->id), 'Sign with KeyRing::signer(), the ring\'s active key.', 'concepts/keys.md');
        }

        $signed = openssl_sign($message, $signature, self::privateKey($this->seed, $this->algorithm), $this->digest());
        $signature = $signed && \is_string($signature) && KeyAlgorithm::ES256 === $this->algorithm ? self::raw($signature) : ($signed ? $signature : null);

        if (!\is_string($signature) || self::SIGNATURE !== \strlen($signature)) {
            throw self::failed(\sprintf('sign with the key "%s"', $this->id));
        }

        return $signature;
    }

    /**
     * Whether this key made the signature: a signature of another algorithm, length or form is refused,
     * never an error.
     */
    public function verify(string $message, string $signature): bool
    {
        $signature = KeyAlgorithm::ES256 === $this->algorithm ? self::der($signature) : $signature;

        // A signature of another length than 64 bytes OpenSSL refuses itself.
        return null !== $signature && 1 === openssl_verify($message, $signature, $this->publicKeyObject(), $this->digest());
    }

    /**
     * The same key without its private part, e.g. to give to a verifier.
     */
    public function withoutPrivatePart(): self
    {
        return new self($this->id, KeyStatus::VerifyOnly, $this->publicKey, algorithm: $this->algorithm);
    }

    private function digest(): int
    {
        return match ($this->algorithm) {
            KeyAlgorithm::EdDSA => self::NO_DIGEST,
            KeyAlgorithm::ES256 => \OPENSSL_ALGO_SHA256,
        };
    }

    private function publicKeyObject(): \OpenSSLAsymmetricKey
    {
        $key = match ($this->algorithm) {
            KeyAlgorithm::EdDSA => openssl_pkey_new(['ed25519' => ['pub_key' => $this->publicKey]]),
            KeyAlgorithm::ES256 => openssl_pkey_new(['ec' => ['curve_name' => 'prime256v1', 'x' => substr($this->publicKey, 0, 32), 'y' => substr($this->publicKey, 32)]]),
        };

        return false === $key ? throw self::failed(\sprintf('read the key "%s"', $this->id)) : $key;
    }

    private static function privateKey(Secret $seed, KeyAlgorithm $algorithm): \OpenSSLAsymmetricKey
    {
        $key = match ($algorithm) {
            KeyAlgorithm::EdDSA => openssl_pkey_new(['ed25519' => ['priv_key' => $seed->reveal()]]),
            KeyAlgorithm::ES256 => openssl_pkey_new(['ec' => ['curve_name' => 'prime256v1', 'd' => $seed->reveal()]]),
        };

        return false === $key ? throw self::failed('make the key') : $key;
    }

    private static function publicKeyOf(Secret $seed, KeyAlgorithm $algorithm): string
    {
        $key = self::privateKey($seed, $algorithm);

        if (KeyAlgorithm::ES256 === $algorithm) {
            [$x, $y] = self::point($key);

            return $x.$y;
        }

        $details = openssl_pkey_get_details($key);
        $public = \is_array($details) && \is_array($details['ed25519'] ?? null) ? ($details['ed25519']['pub_key'] ?? null) : null;

        return \is_string($public) ? $public : throw self::failed('give the Ed25519 public key');
    }

    /**
     * A P-256 key's x, y and, if it has it, d, 32 bytes each.
     *
     * @return array{string, string, ?string}
     */
    private static function point(\OpenSSLAsymmetricKey $key): array
    {
        $details = openssl_pkey_get_details($key);
        $ec = \is_array($details) && \is_array($details['ec'] ?? null) ? $details['ec'] : [];
        [$x, $y, $d] = [$ec['x'] ?? null, $ec['y'] ?? null, $ec['d'] ?? null];

        if (!\is_string($x) || !\is_string($y)) {
            throw self::failed('give the P-256 point');
        }

        // OpenSSL leaves out leading zeros.
        return [str_pad($x, 32, "\0", \STR_PAD_LEFT), str_pad($y, 32, "\0", \STR_PAD_LEFT), \is_string($d) ? str_pad($d, 32, "\0", \STR_PAD_LEFT) : null];
    }

    /**
     * ECDSA's r and s, 32 bytes each, from OpenSSL's DER: SEQUENCE { INTEGER r, INTEGER s }.
     */
    private static function raw(string $der): ?string
    {
        if (1 !== preg_match('/^\x30(.)\x02(.)/s', $der, $head) || \ord($head[1]) !== \strlen($der) - 2) {
            return null;
        }

        $r = substr($der, 4, \ord($head[2]));
        $rest = substr($der, 4 + \ord($head[2]));

        if (1 !== preg_match('/^\x02(.)/s', $rest, $length) || \ord($length[1]) !== \strlen($rest) - 2) {
            return null;
        }

        $s = substr($rest, 2);
        $r = ltrim($r, "\0");
        $s = ltrim($s, "\0");

        return \strlen($r) <= 32 && \strlen($s) <= 32 ? str_pad($r, 32, "\0", \STR_PAD_LEFT).str_pad($s, 32, "\0", \STR_PAD_LEFT) : null;
    }

    /**
     * OpenSSL's DER from r and s, 32 bytes each; null for a signature of another length.
     */
    private static function der(string $raw): ?string
    {
        if (self::SIGNATURE !== \strlen($raw)) {
            return null;
        }

        $integer = static function (string $bytes): string {
            $bytes = ltrim($bytes, "\0");
            // Positive: a leading zero byte where the high bit is set; zero is one zero byte.
            $bytes = '' === $bytes || \ord($bytes[0]) >= 0x80 ? "\0".$bytes : $bytes;

            return "\x02".\chr(\strlen($bytes)).$bytes;
        };
        $sequence = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32));

        return "\x30".\chr(\strlen($sequence)).$sequence;
    }

    private static function failed(string $what): \RuntimeException
    {
        // The queue may hold older errors: the last one is this failure's.
        $last = 'the algorithm isn\'t available';

        while (false !== ($error = openssl_error_string())) {
            $last = $error;
        }

        return new \RuntimeException(\sprintf('OpenSSL couldn\'t %s: %s', $what, $last));
    }
}
