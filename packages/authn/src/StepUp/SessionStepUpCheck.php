<?php

declare(strict_types=1);

namespace YorvoID\Authn\StepUp;

use YorvoID\Authn\Session\Session;
use YorvoID\Core\Authorization\Ground;
use YorvoID\Core\Authorization\StepUpCheck;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\WaitRemedy;
use YorvoID\Core\Operation\Actor;

/**
 * A step-up asked by a permit, proved by the actor's session as StepUp checks it; held while an account
 * recovery cools down, so a recovered account doesn't give strict abilities right away.
 *
 * @api
 */
final readonly class SessionStepUpCheck implements StepUpCheck
{
    public function __construct(
        private StepUp $stepUp,
    ) {
    }

    public function check(Actor $actor, StepUpRemedy $required): Truth
    {
        if (!$actor instanceof Session) {
            return Truth::fails('Only a session proves who it is.');
        }

        try {
            $this->stepUp->require($actor, new AuthenticationRequirement($required->within, $required->multiFactor, $required->phishingResistant, $required->operation, heldAfterRecovery: true));
        } catch (StepUpRequired) {
            return Truth::fails('The session hasn\'t proved who it is recently enough.', $required);
        } catch (HeldAfterRecovery $held) {
            return Truth::fails('The account was recovered recently.', new WaitRemedy($held->until));
        }

        return Truth::holds('The session proved who it is recently.', Ground::evidence('step_up'));
    }
}
