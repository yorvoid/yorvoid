<?php

declare(strict_types=1);

namespace YorvoID\Core\Log\Checkpoint;

use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Realm;

/**
 * Where a realm's security log stood at a moment, signed with the key ring's active key and kept
 * outside the database (AUD-003): someone who can write the database can rewrite the log and its head
 * together, but not a checkpoint. Verification against checkpoints finds entries cut off from the end,
 * and any entry up to a checkpoint that was changed, removed or recomputed.
 *
 * @api
 */
final readonly class Checkpoint
{
    public function __construct(
        public Realm $realm,
        public int $sequence,
        public string $hash,
        public \DateTimeImmutable $takenAt,
        public string $keyId,
        public string $signature,
    ) {
    }

    /**
     * What the signature covers: the realm, the entry and its hash, and when.
     */
    public static function message(Realm $realm, int $sequence, string $hash, \DateTimeImmutable $takenAt): string
    {
        return implode("\n", ['yorvoid-security-log-checkpoint/1', $realm->name, (string) $sequence, $hash, (string) $takenAt->getTimestamp()]);
    }

    public function isSignedBy(KeyRing $keys): bool
    {
        return $keys->verify($this->keyId, self::message($this->realm, $this->sequence, $this->hash, $this->takenAt), $this->signature);
    }

    /**
     * @return array{realm: string, sequence: int, hash: string, taken_at: int, key: string, signature: string}
     */
    public function toArray(): array
    {
        return ['realm' => $this->realm->name, 'sequence' => $this->sequence, 'hash' => $this->hash, 'taken_at' => $this->takenAt->getTimestamp(), 'key' => $this->keyId, 'signature' => base64_encode($this->signature)];
    }

    /**
     * What toArray() gave; null if it isn't that.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data) || !\is_string($data['realm'] ?? null) || !\is_int($data['sequence'] ?? null) || !\is_string($data['hash'] ?? null) || !\is_int($data['taken_at'] ?? null) || !\is_string($data['key'] ?? null) || !\is_string($data['signature'] ?? null)) {
            return null;
        }

        $signature = base64_decode($data['signature'], true);

        return false === $signature ? null : new self(new Realm($data['realm']), $data['sequence'], $data['hash'], new \DateTimeImmutable('@'.$data['taken_at']), $data['key'], $signature);
    }
}
