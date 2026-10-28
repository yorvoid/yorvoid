<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

use CBOR\Decoder;
use CBOR\MapObject;
use CBOR\StringStream;

/**
 * A passkey whose signatures the verifier accepts (INT-004): its public key, kept by the verifier itself.
 *
 * @api
 */
final readonly class Key
{
    /** The COSE algorithms accepted: ES256, EdDSA, RS256 */
    public const array ALGORITHMS = [-7, -8, -257];

    /**
     * @param string $credentialId The authenticator's ID for it, raw bytes
     * @param string $publicKey    COSE-encoded, raw bytes
     * @param string $userHandle   The user handle it was registered with, raw bytes
     * @param int    $algorithm    Its COSE algorithm, from the public key (INT-015)
     * @param string $label        What people call it, e.g. "Ana's YubiKey"
     * @param int    $signCount    The authenticator's signature counter at its last use here
     */
    public function __construct(
        public string $credentialId,
        public string $publicKey,
        public string $userHandle,
        public int $algorithm,
        public string $label,
        public \DateTimeImmutable $addedAt,
        public int $signCount = 0,
    ) {
    }

    /**
     * A key from the parameters of an "add key" intent, as the console sends them.
     *
     * @param array<string, mixed> $parameters As OperationPolicy::ADD_KEY's
     *
     * @return self|null Null if they don't describe a key with an accepted algorithm
     */
    public static function fromParameters(array $parameters, \DateTimeImmutable $addedAt): ?self
    {
        $text = static fn (string $name): ?string => \is_string($parameters[$name] ?? null) ? $parameters[$name] : null;
        $credentialId = Base64Url::decode($text('credential_id') ?? '');
        $publicKey = Base64Url::decode($text('public_key') ?? '');
        $userHandle = Base64Url::decode($text('user_handle') ?? '');
        $label = $text('label');
        $algorithm = null === $publicKey ? null : self::algorithm($publicKey);

        if (null === $credentialId || '' === $credentialId || null === $userHandle || '' === $userHandle || null === $publicKey || null === $algorithm || null === $label) {
            return null;
        }

        return new self($credentialId, $publicKey, $userHandle, $algorithm, $label, $addedAt);
    }

    /**
     * The ID people and logs see: the credential ID in base64url.
     */
    public function id(): string
    {
        return Base64Url::encode($this->credentialId);
    }

    public function withSignCount(int $signCount): self
    {
        return new self($this->credentialId, $this->publicKey, $this->userHandle, $this->algorithm, $this->label, $this->addedAt, $signCount);
    }

    /**
     * The algorithm of a COSE key, if it's one accepted.
     */
    private static function algorithm(string $publicKey): ?int
    {
        try {
            $key = Decoder::create()->decode(new StringStream($publicKey));
        } catch (\Throwable) {
            return null;
        }

        // Encoded back, it must be the same bytes: nothing after it, nothing encoded two ways.
        if (!$key instanceof MapObject || (string) $key !== $publicKey) {
            return null;
        }

        // CBOR's integers come out as text.
        $algorithm = $key->normalize()[3] ?? null;
        $algorithm = \is_string($algorithm) ? (int) $algorithm : null;

        return \in_array($algorithm, self::ALGORITHMS, true) ? $algorithm : null;
    }
}
