<?php

declare(strict_types=1);

namespace YorvoID\Core\Key;

use YorvoID\Core\Crypto\Mac;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Secret;

/**
 * A key that MACs what YorvoID records but mustn't disclose, e.g. the attributes a decision read: 32
 * random bytes, with its ID and status, and when it stopped being active. It's kept in the key ring apart
 * from the signing keys, by type: nothing signs or verifies with it, and it has no public part, so a
 * public key set never holds it.
 *
 * @api
 */
final readonly class MacKey
{
    /** Its algorithm, as in JSON Web Keys (RFC 7518 §3.2) */
    public const string ALGORITHM = 'HS256';

    private const string ID = '/^[A-Za-z0-9._-]{1,64}$/D';

    private const int BYTES = 32;

    /**
     * @param \DateTimeImmutable|null $since   When it stopped being active; null while it is
     * @param \DateTimeImmutable|null $created When it was made, if known
     *
     * @throws IntegratorError When its ID or key isn't one
     */
    public function __construct(
        public string $id,
        public KeyStatus $status,
        public Secret $key,
        public ?\DateTimeImmutable $since = null,
        public ?\DateTimeImmutable $created = null,
    ) {
        if (1 !== preg_match(self::ID, $id)) {
            throw new IntegratorError(\sprintf('"%s" isn\'t a key ID.', $id), 'A key ID is 1 to 64 letters, digits, dots, dashes or underscores.', 'concepts/keys.md');
        }

        if (self::BYTES !== \strlen($key->reveal())) {
            throw new IntegratorError(\sprintf('The MAC key "%s" isn\'t %d bytes.', $id, self::BYTES), 'Generate it: MacKey::generate(), or yorvoid:keys:rotate --mac.', 'concepts/keys.md#mac-keys');
        }
    }

    /**
     * A new active key.
     */
    public static function generate(string $id, ?\DateTimeImmutable $now = null): self
    {
        return new self($id, KeyStatus::Active, Secret::random(self::BYTES), created: $now);
    }

    /**
     * The 32-byte HMAC-SHA256 of a message.
     */
    public function mac(string $message): string
    {
        return Mac::sha256($this->key, $message);
    }

    /**
     * The same key, no longer active from that moment.
     */
    public function retired(\DateTimeImmutable $at): self
    {
        return new self($this->id, KeyStatus::VerifyOnly, $this->key, $this->since ?? $at, $this->created);
    }
}
