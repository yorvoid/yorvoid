<?php

declare(strict_types=1);

namespace YorvoID\Delegation\Change;

use YorvoID\Core\Authorization\AttenuatedByParent;
use YorvoID\Core\Authorization\ChangesAuthority;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Delegation\Delegation;
use YorvoID\Delegation\DelegationAbilities;

/**
 * Passes part of the actor's authority on to another principal (06 §5.1): its own, or, naming a parent,
 * a delegation it holds, within it on every dimension.
 *
 * @implements ChangesAuthority<Delegation>
 *
 * @api
 *
 * @experimental
 */
#[Operation('yorvoid.delegation.issue', 'Delegate authority', AuthorizedBy::Ability, ability: DelegationAbilities::ISSUE, inverse: 'yorvoid.delegation.revoke')]
final readonly class IssueDelegation implements ChangesAuthority, AttenuatedByParent
{
    /**
     * @param list<string>            $actions     Action names or patterns of whole segments, e.g. "content.report.*"
     * @param \DateTimeImmutable      $expiresAt   When it stops: it's never forever
     * @param list<string>            $redelegable Which of its actions, or patterns, may be passed on again
     * @param int                     $depth       How many times they may: 0 when they may not
     * @param string|null             $execution   The task it's bound to, if any
     * @param StepUpRemedy|null       $stepUp      A proof the delegate must show for each use, if any
     * @param DelegationId|null       $parent      The delegation the actor passes on, if it isn't its own authority
     * @param \DateTimeImmutable|null $notBefore   When it starts; now if not given
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $delegate,
        public array $actions,
        public Selector $resources,
        public \DateTimeImmutable $expiresAt,
        public ?string $purpose = null,
        public array $redelegable = [],
        public int $depth = 0,
        public ?string $execution = null,
        public ?StepUpRemedy $stepUp = null,
        public ?DelegationId $parent = null,
        public ?\DateTimeImmutable $notBefore = null,
    ) {
    }

    public function attenuatedBy(): ?DelegationId
    {
        return $this->parent;
    }
}
