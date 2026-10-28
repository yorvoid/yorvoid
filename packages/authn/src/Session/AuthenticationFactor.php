<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use YorvoID\Core\Time;

/**
 * One proof in a session: how, when, and how strong. Decisions that need a recent or a strong proof
 * ("a phishing-resistant method in the last 5 minutes") read these.
 *
 * @api
 */
final readonly class AuthenticationFactor
{
    public \DateTimeImmutable $at;

    /**
     * @param bool        $multiFactor       Whether this proof alone is two factors, e.g. a passkey with user verification
     *                                       (something you have, unlocked by something you know or are). NIST SP 800-63B-4
     * @param bool        $phishingResistant Whether it can't be relayed by a look-alike site, e.g. a passkey
     * @param string|null $operation         The operation a step-up was for, e.g. "yorvoid.credential.remove": it counts
     *                                       for that operation only (ATN-071). Null for signing in, which counts for any
     */
    public function __construct(
        public AuthenticationMethod $method,
        \DateTimeImmutable $at,
        public bool $multiFactor = false,
        public bool $phishingResistant = false,
        public ?string $operation = null,
    ) {
        $this->at = Time::stored($at);
    }

    /**
     * Whether this proof makes the other one redundant: the same method, as strong, for the same purpose,
     * and no older.
     */
    public function supersedes(self $other): bool
    {
        return $this->method === $other->method
            && $this->multiFactor === $other->multiFactor
            && $this->phishingResistant === $other->phishingResistant
            && $this->operation === $other->operation
            && $this->at >= $other->at;
    }
}
