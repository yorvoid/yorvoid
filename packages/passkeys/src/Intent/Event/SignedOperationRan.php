<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Intent\Event;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\IntentVerifier\Intent;

/**
 * An operation the principal signed ran, and it committed (INT-012): tell them, through a channel the
 * console doesn't control, e.g. with SignedOperationNotification.
 *
 * @api
 */
final readonly class SignedOperationRan
{
    /**
     * @param string $key The verifier's ID of the key that signed
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public Intent $intent,
        public string $key,
        public \DateTimeImmutable $at,
        public Origin $origin,
    ) {
    }
}
