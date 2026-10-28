<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * An authentication session, kept server-side (SES-001): whose it is, how they proved it, on what
 * device, and until when. The client holds only its token; the application's own PHP session holds
 * only that token (SES-008).
 *
 * @api
 */
final readonly class Session
{
    /**
     * @param int         $principalStatusVersion The principal's status version when the session started; the
     *                                            session is valid only while it's still the principal's
     * @param string|null $device                 What the person would recognise, e.g. "Firefox on Linux"
     * @param string|null $address                The IP address it started from
     */
    public function __construct(
        public SessionId $id,
        public Realm $realm,
        public PrincipalId $principalId,
        public int $principalStatusVersion,
        public AuthenticationContext $authentication,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $lastSeenAt,
        public \DateTimeImmutable $expiresAt,
        public \DateTimeImmutable $idleExpiresAt,
        public ?string $device = null,
        public ?string $address = null,
        public ?\DateTimeImmutable $endedAt = null,
        public ?SessionEndReason $endReason = null,
    ) {
    }

    /**
     * Not ended, and within both its limits at that moment.
     */
    public function isCurrentAt(\DateTimeImmutable $now): bool
    {
        return null === $this->endedAt && $now < $this->expiresAt && $now < $this->idleExpiresAt;
    }

    public function isEnded(): bool
    {
        return null !== $this->endedAt;
    }
}
