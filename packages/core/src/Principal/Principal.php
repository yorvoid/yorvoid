<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Realm;
use YorvoID\Core\Time;

/**
 * Anything that can act or be given authority: a person, a service, a workload, an agent, the holder
 * of a capability (IDN-001). YorvoID keeps the security state; applications keep profiles (names,
 * avatars, preferences) in their own tables, linked by the principal's ID (D-05).
 *
 * @api
 */
final readonly class Principal
{
    /**
     * @param int $statusVersion Counts the principal's status changes, starting at 1. Whatever was granted
     *                           to a principal at one version (a session, for example) can require the same
     *                           version later, so it doesn't outlive a suspension, even one that was undone
     */
    public function __construct(
        public PrincipalId $id,
        public Realm $realm,
        public PrincipalKind $kind,
        public PrincipalStatus $status,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public \DateTimeImmutable $statusChangedAt,
        public int $statusVersion = 1,
    ) {
    }

    /**
     * The same principal with another status, changed at the given time.
     *
     * @throws InvalidStatusChange When the status can't change to that one (IDN-004)
     */
    public function withStatus(PrincipalStatus $status, \DateTimeImmutable $at): self
    {
        if (!$this->status->canBecome($status)) {
            throw new InvalidStatusChange($this, $status);
        }

        $at = Time::stored($at);

        return new self($this->id, $this->realm, $this->kind, $status, $this->createdAt, $at, $at, $this->statusVersion + 1);
    }

    public function isActive(): bool
    {
        return PrincipalStatus::Active === $this->status;
    }
}
