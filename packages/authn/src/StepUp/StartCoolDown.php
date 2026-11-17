<?php

declare(strict_types=1);

namespace YorvoID\Authn\StepUp;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Holds a principal's sensitive operations for the configured cool-down, after an account recovery
 * (ATN-029). Only a recovery causes it: RecoverWithCode, RedeemRecoveryCode and UseRecoveryLink.
 *
 * @implements Command<void>
 *
 * @api
 */
#[Operation('yorvoid.recovery.cool_down', 'Hold sensitive operations after an account recovery', AuthorizedBy::Consequence)]
final readonly class StartCoolDown implements Command, ConcernsPrincipal
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
    ) {
    }

    public function principal(): PrincipalId
    {
        return $this->principalId;
    }
}
