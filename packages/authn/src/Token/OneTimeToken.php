<?php

declare(strict_types=1);

namespace YorvoID\Authn\Token;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A one-time token as YorvoID keeps it (ATN-030): who it's for, what for, and until when. The secret
 * part is kept only as a hash.
 *
 * @api
 */
final readonly class OneTimeToken
{
    /**
     * @param string      $selector Finds the token; not secret
     * @param string      $purpose  What it's for, e.g. "yorvoid.invitation"
     * @param string|null $email    The address it was sent to, in normalized form: it works only while
     *                              that's still the principal's address, as whoever reads that inbox
     *                              now may not be them
     */
    public function __construct(
        public string $selector,
        public Realm $realm,
        public PrincipalId $principalId,
        public string $purpose,
        public ?string $email,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
