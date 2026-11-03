<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * A change to the verifier's keys, signed and waiting to take effect (INT-013, INT-017): the owners are
 * told when it's asked for, any current key can cancel it until then, and it applies on its own once
 * due. A key being removed keeps signing until then; a key being added doesn't sign yet.
 *
 * @api
 */
final readonly class KeyChange
{
    public const string ADD = 'add';

    public const string REMOVE = 'remove';

    /**
     * @param string $id     Its own ID, to cancel it by
     * @param string $kind   self::ADD or self::REMOVE
     * @param Key    $key    The key added or removed
     * @param string $signer The ID of the key that signed it; the key itself, for a bootstrap
     */
    public function __construct(
        public string $id,
        public string $kind,
        public Key $key,
        public \DateTimeImmutable $requestedAt,
        public \DateTimeImmutable $effectiveAt,
        public string $signer,
    ) {
    }
}
