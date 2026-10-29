<?php

declare(strict_types=1);

namespace YorvoID\VirtualAuthenticator;

/**
 * A software authenticator for tests: it answers WebAuthn options as a browser with a real authenticator
 * would (ES256 keys from OpenSSL or EdDSA from libsodium, CBOR, "none" attestation), and can be told to
 * misbehave: skip user verification, report a counter, answer for another origin or challenge. It works
 * with any WebAuthn server, not only YorvoID.
 *
 * For end-to-end tests in real browsers, use the browser's own virtual authenticator (CDP).
 *
 * @api
 */
final class VirtualAuthenticator
{
    /** @var list<array{id: string, key: \OpenSSLAsymmetricKey|string, publicKey: string, userHandle: string, counter: int}> The key: OpenSSL's for ES256, a libsodium secret key for EdDSA; the public key COSE-encoded */
    public array $credentials = [];

    /**
     * @param bool $synced  Whether its passkeys sync (backup-eligible and backed up, counter always 0)
     * @param bool $edwards Whether its keys are Ed25519 (EdDSA) rather than P-256 (ES256)
     */
    public function __construct(
        private readonly string $origin = 'https://example.com',
        private readonly bool $synced = false,
        private readonly bool $edwards = false,
    ) {
    }

    /**
     * Creates a passkey for navigator.credentials.create()'s options, and answers.
     *
     * @return string The PublicKeyCredential, as JSON
     */
    public function register(string $options, bool $userVerified = true, ?string $origin = null, ?string $credentialId = null): string
    {
        $options = self::json($options);
        $rp = $options['rp'];
        $user = $options['user'];
        \assert(\is_array($rp) && \is_string($rp['id']) && \is_array($user) && \is_string($user['id']) && \is_string($options['challenge']));
        $id = $credentialId ?? random_bytes(16);

        if ($this->edwards) {
            $pair = sodium_crypto_sign_keypair();
            $key = sodium_crypto_sign_secretkey($pair);
            $publicKey = self::cbor([1 => 1, 3 => -8, -1 => 6, -2 => new CborBytes(sodium_crypto_sign_publickey($pair))]);
        } else {
            $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            \assert($key instanceof \OpenSSLAsymmetricKey);
            $details = openssl_pkey_get_details($key);
            \assert(\is_array($details) && \is_array($details['ec']) && \is_string($details['ec']['x']) && \is_string($details['ec']['y']));
            // OpenSSL drops leading zero bytes; COSE's coordinates are exactly 32 bytes.
            $x = str_pad($details['ec']['x'], 32, "\0", \STR_PAD_LEFT);
            $y = str_pad($details['ec']['y'], 32, "\0", \STR_PAD_LEFT);
            $publicKey = self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => new CborBytes($x), -3 => new CborBytes($y)]);
        }

        $this->credentials[] = ['id' => $id, 'key' => $key, 'publicKey' => $publicKey, 'userHandle' => self::decode($user['id']), 'counter' => 0];
        $authData = hash('sha256', $rp['id'], true).\chr(0x41 | ($userVerified ? 0x04 : 0) | ($this->synced ? 0x18 : 0)).pack('N', 0).str_repeat("\0", 16).pack('n', \strlen($id)).$id.$publicKey;
        $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => $origin ?? $this->origin, 'crossOrigin' => false], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        return self::credential($id, [
            'clientDataJSON' => self::encode($clientData),
            'attestationObject' => self::encode(self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new CborBytes($authData)])),
            'transports' => ['internal', 'hybrid'],
        ]);
    }

    /**
     * Signs in with one of its passkeys for navigator.credentials.get()'s options, and answers.
     *
     * @param int|null    $counter    What to report as the signature counter; one more than last time if not given
     * @param string|null $userHandle Whose passkey to claim it is, raw bytes; the one it was made for if not given
     *
     * @return string The PublicKeyCredential, as JSON
     */
    public function signIn(string $options, int $credential = 0, bool $userVerified = true, ?int $counter = null, ?string $origin = null, ?string $challenge = null, ?string $rpId = null, ?string $userHandle = null): string
    {
        $options = self::json($options);
        \assert(\is_string($options['rpId']) && \is_string($options['challenge']));
        $passkey = $this->credentials[$credential];
        $counter ??= $this->synced ? 0 : $passkey['counter'] + 1;
        $this->credentials[$credential]['counter'] = $counter;

        $authData = hash('sha256', $rpId ?? $options['rpId'], true).\chr(0x01 | ($userVerified ? 0x04 : 0) | ($this->synced ? 0x18 : 0)).pack('N', $counter);
        $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge ?? $options['challenge'], 'origin' => $origin ?? $this->origin, 'crossOrigin' => false], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $signed = $authData.hash('sha256', $clientData, true);

        if (\is_string($passkey['key']) && '' !== $passkey['key']) {
            $signature = sodium_crypto_sign_detached($signed, $passkey['key']);
        } else {
            \assert($passkey['key'] instanceof \OpenSSLAsymmetricKey);
            openssl_sign($signed, $signature, $passkey['key'], \OPENSSL_ALGO_SHA256);
            \assert(\is_string($signature));
        }

        return self::credential($passkey['id'], [
            'clientDataJSON' => self::encode($clientData),
            'authenticatorData' => self::encode($authData),
            'signature' => self::encode($signature),
            'userHandle' => self::encode($userHandle ?? $passkey['userHandle']),
        ]);
    }

    /**
     * Makes a passkey without a ceremony, e.g. to add to an intent verifier.
     *
     * @return int Its number, for signIn()
     */
    public function create(): int
    {
        $this->register((string) json_encode(['rp' => ['id' => 'example.com'], 'user' => ['id' => self::encode(random_bytes(32))], 'challenge' => self::encode(random_bytes(32))]));

        return \count($this->credentials) - 1;
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $text): string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);
        \assert(\is_string($bytes));

        return $bytes;
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function credential(string $id, array $response): string
    {
        return json_encode(['id' => self::encode($id), 'rawId' => self::encode($id), 'type' => 'public-key', 'response' => $response, 'clientExtensionResults' => new \stdClass()], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $json): array
    {
        $decoded = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return array_filter($decoded, is_string(...), \ARRAY_FILTER_USE_KEY);
    }

    /**
     * The little CBOR WebAuthn needs: integers, byte and text strings, maps.
     */
    private static function cbor(mixed $value): string
    {
        $head = static fn (int $major, int $length): string => match (true) {
            $length < 24 => pack('C', $major << 5 | $length),
            $length < 256 => pack('CC', $major << 5 | 24, $length),
            default => pack('Cn', $major << 5 | 25, $length),
        };

        return match (true) {
            \is_int($value) && $value >= 0 => $head(0, $value),
            \is_int($value) => $head(1, -1 - $value),
            $value instanceof CborBytes => $head(2, \strlen($value->bytes)).$value->bytes,
            \is_string($value) => $head(3, \strlen($value)).$value,
            \is_array($value) => $head(5, \count($value)).implode('', array_map(static fn (int|string $key, mixed $item): string => self::cbor($key).self::cbor($item), array_keys($value), $value)),
            default => throw new \LogicException('Not encodable as CBOR here.'),
        };
    }
}
