<?php

declare(strict_types=1);

namespace YorvoID\Core\Key;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Secret;

/**
 * The signing keys of an installation: exactly one active key signs, and every key in the ring verifies
 * (OPS-001). Rotating is: add a new verify-only key, make it active and the old one verify-only, and
 * remove the old one once nothing it signed needs checking.
 *
 * Keys are kept as a JSON Web Key Set (RFC 7517, Ed25519 keys as in RFC 8037), with a "status" member
 * for each key, in an environment variable or a file only its owner can read (OPS-002); never in the
 * database next to what they sign.
 *
 * @api
 */
final readonly class KeyRing
{
    /** @var array<string, SigningKey> By ID */
    private array $keys;

    /**
     * @param iterable<SigningKey> $keys
     */
    public function __construct(iterable $keys)
    {
        $byId = [];
        $active = [];

        foreach ($keys as $key) {
            if (isset($byId[$key->id])) {
                throw new IntegratorError(\sprintf('Two keys have the ID "%s".', $key->id), 'Give each key its own ID.', 'concepts/keys.md');
            }

            $byId[$key->id] = $key;

            if (KeyStatus::Active === $key->status) {
                $active[] = $key->id;
            }
        }

        if (1 !== \count($active)) {
            throw new IntegratorError(\sprintf('The key ring has %d active keys (%s).', \count($active), implode(', ', $active)), 'Exactly one key is active: it signs. Make the others verify-only.', 'concepts/keys.md#rotating-keys');
        }

        $this->keys = $byId;
    }

    /**
     * The key that signs.
     */
    public function signer(): SigningKey
    {
        foreach ($this->keys as $key) {
            if (KeyStatus::Active === $key->status) {
                return $key;
            }
        }

        throw new \LogicException('Unreachable: a key ring has exactly one active key.');
    }

    public function get(string $id): ?SigningKey
    {
        return $this->keys[$id] ?? null;
    }

    /**
     * Whether the key with that ID, if it's in the ring, made the signature.
     */
    public function verify(string $keyId, string $message, string $signature): bool
    {
        return $this->get($keyId)?->verify($message, $signature) ?? false;
    }

    /**
     * @return list<SigningKey>
     */
    public function keys(): array
    {
        return array_values($this->keys);
    }

    /**
     * Rotation's first step: the ring with a key added, e.g. a new verify-only one (OPS-003).
     */
    public function with(SigningKey $key): self
    {
        return new self([...$this->keys, $key]);
    }

    /**
     * Rotation's second step: the ring with that key active, and the one that was verify-only.
     */
    public function activating(string $id): self
    {
        $key = $this->known($id);

        return new self(array_map(static fn (SigningKey $other): SigningKey => new SigningKey($other->id, $other === $key ? KeyStatus::Active : KeyStatus::VerifyOnly, $other->publicKey, $other->seed), $this->keys));
    }

    /**
     * Rotation's last step: the ring without a key nothing it signed needs any more.
     */
    public function without(string $id): self
    {
        if (KeyStatus::Active === $this->known($id)->status) {
            throw new IntegratorError(\sprintf('The key "%s" is active: it can\'t be removed.', $id), 'Make another key active first; then remove this one once nothing it signed still needs checking.', 'concepts/keys.md#rotating-keys');
        }

        return new self(array_filter($this->keys, static fn (SigningKey $key): bool => $key->id !== $id));
    }

    /**
     * Writes the ring, private parts included, to a file only its owner can read; replacing an existing
     * one at once, so that no reader ever sees half a key set.
     */
    public function toFile(string $path): void
    {
        $temporary = @tempnam(\dirname($path), '.yorvoid-keys-');

        if (false === $temporary || \dirname($temporary) !== \dirname($path)) {
            throw new IntegratorError(\sprintf('The key file "%s" can\'t be written.', $path), 'Check that its folder exists, and that the application\'s user may write to it.', 'concepts/keys.md#where-keys-are-kept');
        }

        // tempnam() creates the file for its owner only (0600).
        file_put_contents($temporary, $this->toJwks(true)->reveal());
        rename($temporary, $path);
    }

    private function known(string $id): SigningKey
    {
        return $this->keys[$id] ?? throw new IntegratorError(\sprintf('The key ring has no key "%s".', $id), \sprintf('Use one of: %s.', implode(', ', array_keys($this->keys))), 'concepts/keys.md#rotating-keys');
    }

    /**
     * Reads a JSON Web Key Set: {"keys": [{"kid", "kty": "OKP", "crv": "Ed25519", "alg": "EdDSA", "x", "d", "status"}]}.
     */
    public static function fromJwks(Secret $json): self
    {
        try {
            // A set, its list, a key, a key's own lists (e.g. "key_ops") and their values: nothing deeper.
            $set = json_decode($json->reveal(), true, 5, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new IntegratorError('The key set isn\'t valid JSON.', 'Provide the key set as YorvoID generates it: {"keys": [ … ]}.', 'concepts/keys.md#where-keys-are-kept', $exception);
        }

        if (!\is_array($set) || !isset($set['keys']) || !\is_array($set['keys']) || !array_is_list($set['keys'])) {
            throw new IntegratorError('The key set has no list of keys.', 'Provide the key set as YorvoID generates it: {"keys": [ … ]}.', 'concepts/keys.md#where-keys-are-kept');
        }

        return new self(array_map(self::fromJwk(...), $set['keys']));
    }

    /**
     * Reads a JSON Web Key Set from a file that only its owner may read.
     */
    public static function fromFile(string $path): self
    {
        $permissions = @fileperms($path);

        if (false === $permissions) {
            throw new IntegratorError(\sprintf('The key file "%s" can\'t be read.', $path), 'Check the path, and that the application\'s user may read it.', 'concepts/keys.md#where-keys-are-kept');
        }

        if (0 !== ($permissions & 0o077)) {
            throw new IntegratorError(\sprintf('The key file "%s" can be read by others than its owner (mode %o).', $path, $permissions & 0o777), \sprintf('Run: chmod 600 %s', $path), 'concepts/keys.md#where-keys-are-kept');
        }

        $json = file_get_contents($path);
        \assert(\is_string($json));

        return self::fromJwks(Secret::fromString($json));
    }

    /**
     * The key set as JSON; with private parts only when asked, e.g. to store it after generating.
     */
    public function toJwks(bool $withPrivateParts = false): Secret
    {
        $keys = [];

        foreach ($this->keys as $key) {
            $jwk = ['kid' => $key->id, 'kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => SigningKey::ALGORITHM, 'use' => 'sig', 'status' => $key->status->value, 'x' => self::base64url($key->publicKey)];

            if ($withPrivateParts && null !== $key->seed) {
                $jwk['d'] = self::base64url($key->seed->reveal());
            }

            $keys[] = $jwk;
        }

        // Pretty, for people who look after key files.
        return Secret::fromString(json_encode(['keys' => $keys], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    }

    private static function fromJwk(mixed $jwk): SigningKey
    {
        if (!\is_array($jwk)) {
            throw new IntegratorError('An entry of the key set isn\'t a key.', 'Each entry of "keys" is an object with "kid", "kty", "crv", "x" and "status".', 'concepts/keys.md#where-keys-are-kept');
        }

        $kid = $jwk['kid'] ?? null;
        $kid = \is_string($kid) ? $kid : '';

        if ('OKP' !== ($jwk['kty'] ?? null) || 'Ed25519' !== ($jwk['crv'] ?? null) || SigningKey::ALGORITHM !== ($jwk['alg'] ?? SigningKey::ALGORITHM)) {
            throw new IntegratorError(\sprintf('The key "%s" isn\'t an Ed25519 key.', $kid), 'YorvoID signs with Ed25519 keys: "kty": "OKP", "crv": "Ed25519".', 'concepts/keys.md');
        }

        $status = KeyStatus::tryFrom(\is_string($jwk['status'] ?? null) ? $jwk['status'] : '');

        if (null === $status) {
            throw new IntegratorError(\sprintf('The key "%s" has no valid status.', $kid), 'Set "status" to "active" or "verify-only".', 'concepts/keys.md#rotating-keys');
        }

        $d = $jwk['d'] ?? null;

        return new SigningKey($kid, $status, self::decode($jwk['x'] ?? null, $kid), null === $d ? null : Secret::fromString(self::decode($d, $kid)));
    }

    private static function decode(mixed $value, string $kid): string
    {
        $decoded = \is_string($value) && 1 === preg_match('/^[A-Za-z0-9_-]+$/D', $value) ? base64_decode(strtr($value, '-_', '+/'), true) : false;

        if (false === $decoded) {
            throw new IntegratorError(\sprintf('A part of the key "%s" isn\'t base64url.', $kid), '"x" and "d" are base64url without padding, as YorvoID writes them.', 'concepts/keys.md#where-keys-are-kept');
        }

        return $decoded;
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
