<?php

declare(strict_types=1);

namespace YorvoID\Delegation;

use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A principal's authority passed on, in part, to another (06 §5.1): for these actions on these
 * resources, for a while, maybe for one purpose or task, and maybe passed on again. A child delegation
 * is within its parent on every dimension, and the whole chain is checked again at every use, up to
 * its root's authority now.
 *
 * @api
 *
 * @experimental
 */
final readonly class Delegation
{
    /**
     * @param DelegationId|null       $parent          The delegation the delegator passes on, if any: none when it's the root's own
     * @param PrincipalId             $root            Whose authority it is, at the top of the chain: the delegator, for a root delegation
     * @param int                     $depth           0 for a root delegation, its parent's + 1 otherwise
     * @param list<string>            $actions         What it may do: the catalogue's actions, expanded at issuance
     * @param list<string>            $patterns        As the delegator wrote them, e.g. "content.report.*", for people
     * @param list<string>            $redelegable     Which of its actions may be passed on again
     * @param int                     $remainingDepth  How many more times they may
     * @param string|null             $execution       The task it's bound to, if any: the application's reference
     * @param StepUpRemedy|null       $stepUp          A proof the delegate must show for each use, if any
     * @param string                  $issuedBy        The decision that permitted issuing it
     * @param int                     $issuedAtVersion The policy version it was issued under: provenance, not a precondition
     * @param \DateTimeImmutable|null $revokedAt       When it was revoked, if it was
     */
    public function __construct(
        public DelegationId $id,
        public Realm $realm,
        public PrincipalId $delegator,
        public PrincipalId $delegate,
        public ?DelegationId $parent,
        public PrincipalId $root,
        public int $depth,
        public array $actions,
        public array $patterns,
        public Selector $resources,
        public ?string $purpose,
        public \DateTimeImmutable $notBefore,
        public \DateTimeImmutable $expiresAt,
        public array $redelegable,
        public int $remainingDepth,
        public ?string $execution,
        public ?StepUpRemedy $stepUp,
        public string $issuedBy,
        public int $issuedAtVersion,
        public \DateTimeImmutable $issuedAt,
        public DelegationStatus $status = DelegationStatus::Active,
        public ?\DateTimeImmutable $revokedAt = null,
        public ?PrincipalId $revokedBy = null,
        public ?string $revokedWhy = null,
    ) {
    }

    /**
     * The same delegation, revoked.
     */
    public function revoked(\DateTimeImmutable $at, ?PrincipalId $by, string $why): self
    {
        return new self($this->id, $this->realm, $this->delegator, $this->delegate, $this->parent, $this->root, $this->depth, $this->actions, $this->patterns, $this->resources, $this->purpose, $this->notBefore, $this->expiresAt, $this->redelegable, $this->remainingDepth, $this->execution, $this->stepUp, $this->issuedBy, $this->issuedAtVersion, $this->issuedAt, DelegationStatus::Revoked, $at, $by, $why);
    }

    public function isActive(): bool
    {
        return DelegationStatus::Active === $this->status;
    }

    /**
     * Whether it's inside its window at that moment: from not-before, up to but not at its expiry.
     */
    public function isCurrentAt(\DateTimeImmutable $at): bool
    {
        return $this->notBefore <= $at && $at < $this->expiresAt;
    }
}
