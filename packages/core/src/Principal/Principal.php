<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Exception\IntegratorError;
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
     * @param int               $statusVersion Counts the principal's status changes, starting at 1. Whatever was granted
     *                                         to a principal at one version (a session, for example) can require the same
     *                                         version later, so it doesn't outlive a suspension, even one that was undone
     * @param PrincipalId|null  $owner         An agent's: who registered it and answers for it (AGT-001); null for any other kind
     * @param AgentProfile|null $profile       An agent's description, for people (experimental)
     *
     * @throws IntegratorError When an agent has no owner, or any other kind has an owner or a profile
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
        public ?PrincipalId $owner = null,
        public ?AgentProfile $profile = null,
    ) {
        if (PrincipalKind::Agent === $kind ? null === $owner : (null !== $owner || null !== $profile)) {
            throw new IntegratorError(\sprintf('The principal %s is %s: only an agent has an owner and a profile, and every agent has an owner.', $id, PrincipalKind::Agent === $kind ? 'an agent without an owner' : 'not an agent, with an owner or a profile'), 'Give an agent its owner, and any other kind neither.', 'concepts/principals.md#agents');
        }
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

        return new self($this->id, $this->realm, $this->kind, $status, $this->createdAt, $at, $at, $this->statusVersion + 1, $this->owner, $this->profile);
    }

    /**
     * The same agent with another owner, changed at the given time.
     */
    public function withOwner(PrincipalId $owner, \DateTimeImmutable $at): self
    {
        return new self($this->id, $this->realm, $this->kind, $this->status, $this->createdAt, Time::stored($at), $this->statusChangedAt, $this->statusVersion, $owner, $this->profile);
    }

    public function isActive(): bool
    {
        return PrincipalStatus::Active === $this->status;
    }
}
