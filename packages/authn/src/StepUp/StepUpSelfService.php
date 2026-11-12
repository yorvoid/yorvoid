<?php

declare(strict_types=1);

namespace YorvoID\Authn\StepUp;

use YorvoID\Authn\Session\Session;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\WaitReason;
use YorvoID\Core\Authorization\WaitRemedy;
use YorvoID\Core\Duration;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\SelfOperation;
use YorvoID\Core\Operation\SelfServicePolicy;

/**
 * What someone changing their own account must show, whatever permits the change: a session, and for
 * some operations a recent proof of who they are (a step-up), as their requirement says. Changing one's
 * email address or username needs a recent proof and waits out a recovery's cool-down: it's where
 * notifications and recovery go. So do suspending or deleting oneself, and issuing oneself a recovery link
 * or a token: what someone who recovered an account they don't own would do.
 *
 * Operations that check more than this themselves (a credential's removal needs a passkey's proof, a new
 * set of recovery codes replacing the old one does too) have no requirement here. A self-service operation
 * it doesn't know (SelfOperation), e.g. an application's own, needs a proof within the re-authentication
 * window; any other command about one's own principal needs nothing unless it's given a requirement.
 *
 * @api
 */
final readonly class StepUpSelfService implements SelfServicePolicy
{
    private Duration $window;

    /** @var array<string, AuthenticationRequirement|null> */
    private array $requirements;

    /**
     * @param array<string, AuthenticationRequirement|null> $requirements By operation, over YorvoID's own; null for none
     * @param Duration|null                                 $window       How recent a proof must be: 5 minutes if not given
     */
    public function __construct(
        private StepUp $stepUp,
        array $requirements = [],
        ?Duration $window = null,
    ) {
        $this->window = $window ?? Duration::minutes(5);
        $this->requirements = [
            'yorvoid.identifier.set' => new AuthenticationRequirement($this->window, operation: 'yorvoid.identifier.set', heldAfterRecovery: true),
            'yorvoid.identifier.remove' => new AuthenticationRequirement($this->window, operation: 'yorvoid.identifier.remove', heldAfterRecovery: true),
            // Through another basis, on oneself (M2-13, H-4).
            'yorvoid.principal.suspend' => new AuthenticationRequirement($this->window, operation: 'yorvoid.principal.suspend', heldAfterRecovery: true),
            'yorvoid.principal.delete' => new AuthenticationRequirement($this->window, operation: 'yorvoid.principal.delete', heldAfterRecovery: true),
            'yorvoid.recovery_link.issue' => new AuthenticationRequirement($this->window, operation: 'yorvoid.recovery_link.issue', heldAfterRecovery: true),
            'yorvoid.token.issue' => new AuthenticationRequirement($this->window, operation: 'yorvoid.token.issue', heldAfterRecovery: true),
            'yorvoid.principal.activate' => null,
            'yorvoid.token.revoke' => null,
            // Their handlers require the step-up themselves, as each needs.
            'yorvoid.credential.remove' => null,
            'yorvoid.credential.rename' => null,
            'yorvoid.recovery_codes.generate' => null,
            'yorvoid.passkey.register' => null,
            // Signing out, here or everywhere, never waits for a proof.
            'yorvoid.session.end' => null,
            'yorvoid.session.end_all' => null,
            ...$requirements,
        ];
    }

    public function decide(OperationDescription $operation, Command $command, Actor $actor): Truth
    {
        $self = $command instanceof SelfOperation;
        $requirement = match (true) {
            \array_key_exists($operation->name, $this->requirements) => $this->requirements[$operation->name],
            // Self-service by declaration; anything else about one's own principal, only if asked (C2).
            $self => new AuthenticationRequirement($this->window, operation: $operation->name),
            default => null,
        };

        if (null === $requirement && !$self) {
            return Truth::holds(\sprintf('"%s" asks nothing more of the actor\'s own account.', $operation->name));
        }

        if (!$actor instanceof Session) {
            return Truth::fails(\sprintf('"%s" runs on one\'s own account from a session only.', $operation->name));
        }

        if (null === $requirement) {
            return Truth::holds('The actor\'s own account, from its session.');
        }

        try {
            $this->stepUp->require($actor, $requirement);
        } catch (StepUpRequired) {
            return Truth::fails('The session hasn\'t proved who it is recently enough.', new StepUpRemedy($requirement->within, $requirement->multiFactor, $requirement->phishingResistant, $requirement->operation));
        } catch (HeldAfterRecovery $held) {
            return Truth::fails('The account was recovered recently.', new WaitRemedy($held->until, WaitReason::Recovered));
        }

        return Truth::holds('The actor\'s own account, from its session, proved recently.');
    }
}
