<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;

/**
 * Adds a proof to a current session, after its principal proved who they are again (ATN-070), and
 * gives it a new token (SES-002). Run by the operation that checked the proof, e.g. StepUpWithPasskey,
 * never on a request's say-so: a way of stepping up of your own is an operation that causes it.
 *
 * @implements Command<Secret|null>
 *
 * @api
 */
#[Operation('yorvoid.session.step_up', 'Step up a session', AuthorizedBy::Consequence)]
final readonly class StepUpSession implements Command, ConcernsPrincipal
{
    /**
     * @param PrincipalId          $principalId Whose session it is: the one who proved who they are
     * @param AuthenticationFactor $factor      The proof, for the operation the step-up was asked for
     */
    public function __construct(
        public Realm $realm,
        public SessionId $sessionId,
        public PrincipalId $principalId,
        public AuthenticationFactor $factor,
    ) {
    }

    public function principal(): PrincipalId
    {
        return $this->principalId;
    }
}
