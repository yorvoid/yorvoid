<?php

declare(strict_types=1);

namespace YorvoID\Authn\StepUp;

use YorvoID\Authn\Credential\CredentialKind;
use YorvoID\Authn\Credential\Credentials;
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
 * Removing a credential and replacing the recovery codes take a phishing-resistant proof, e.g. a passkey's,
 * and wait out a recovery; registering a passkey takes a recent proof. Every requirement is decided here,
 * as a Deny with its remedy, before the operation is permitted and recorded (G-8). A self-service operation
 * it doesn't know (SelfOperation), e.g. an application's own, needs a proof within the re-authentication
 * window; any other command about one's own principal needs nothing unless it's given a requirement.
 *
 * @api
 */
final readonly class StepUpSelfService implements SelfServicePolicy
{
    private const string GENERATE_CODES = 'yorvoid.recovery_codes.generate';

    /** What changes how someone signs in: an operation that is one, or causes one */
    private const array SIGN_IN_CHANGES = ['yorvoid.credential.add', 'yorvoid.credential.remove'];

    private Duration $window;

    /** @var array<string, AuthenticationRequirement|null> */
    private array $requirements;

    /**
     * @param array<string, AuthenticationRequirement|null> $requirements By operation, over YorvoID's own; null for none
     * @param Duration|null                                 $window       How recent a proof must be: 5 minutes if not given
     * @param Credentials|null                              $credentials  To tell a first set of recovery codes, which asks a recent proof, from one
     *                                                                    replacing the owner's, which asks a phishing-resistant one; without it, every set asks that
     */
    public function __construct(
        private StepUp $stepUp,
        array $requirements = [],
        ?Duration $window = null,
        private ?Credentials $credentials = null,
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
            // Taking a way back in away, or replacing it, takes a phishing-resistant proof, which a stolen
            // code isn't, and waits out a recovery (G-8: decided here, never thrown by the handler).
            'yorvoid.credential.remove' => new AuthenticationRequirement($this->window, phishingResistant: true, operation: 'yorvoid.credential.remove', heldAfterRecovery: true),
            'yorvoid.recovery_codes.generate' => new AuthenticationRequirement($this->window, phishingResistant: true, operation: 'yorvoid.recovery_codes.generate', heldAfterRecovery: true),
            // A new passkey, as its ceremony's start asks (Passkeys::beginRegistration()).
            'yorvoid.passkey.register' => new AuthenticationRequirement($this->window, operation: 'yorvoid.credential.add'),
            'yorvoid.credential.rename' => null,
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
            // Changing how someone signs in, by an operation not in the table: the strictest proof, so a missing
            // entry asks too much, never too little (G-8).
            self::changesSignIn($operation) => new AuthenticationRequirement($this->window, phishingResistant: true, operation: $operation->name, heldAfterRecovery: true),
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

        // A first set of recovery codes replaces nothing: it's a new credential, as a passkey is. Only a new set
        // makes the owner's saved codes useless.
        if (self::GENERATE_CODES === $operation->name && null !== $this->credentials && !$this->hasRecoveryCodes($actor)) {
            $requirement = new AuthenticationRequirement($this->window, operation: 'yorvoid.credential.add');
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

    private function hasRecoveryCodes(Session $session): bool
    {
        \assert(null !== $this->credentials);

        foreach ($this->credentials->of($session->realm, $session->principalId) as $credential) {
            if (CredentialKind::RecoveryCodes === $credential->kind) {
                return true;
            }
        }

        return false;
    }

    private static function changesSignIn(OperationDescription $operation): bool
    {
        return \in_array($operation->name, self::SIGN_IN_CHANGES, true) || [] !== array_intersect($operation->causes, self::SIGN_IN_CHANGES);
    }
}
