<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Invitation\Invitations;
use YorvoID\Authn\Recovery\RecoveryCodes;
use YorvoID\Authn\Recovery\RecoveryLinks;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Setup\SetupCodes;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Core\Duration;
use YorvoID\Core\Principal\Principals;
use YorvoID\Flows\Flow;
use YorvoID\Passkeys\Passkeys;

/**
 * The passkey-first journeys (UX-009): signing in, claiming a fresh installation, accepting an
 * invitation, recovering, adding a passkey, stepping up, and confirming a signed operation. Each ends
 * with a way to sign in that works: there's no dead end.
 *
 * @api
 */
final readonly class PasskeyFlows
{
    /** A passkey */
    public const string SIGN_IN = 'yorvoid.sign_in';

    /** The setup code, then the owner's passkey and recovery codes */
    public const string SETUP = 'yorvoid.setup';

    /** Accepting, then a passkey and recovery codes */
    public const string INVITATION = 'yorvoid.invitation';

    /** A recovery code, then a new passkey (or later) and new codes when none are left */
    public const string RECOVER_WITH_CODE = 'yorvoid.recover_with_code';

    /** A recovery link, then a new passkey (or later) and new codes when none are left */
    public const string RECOVER_WITH_LINK = 'yorvoid.recover_with_link';

    /** A step-up when the proof isn't recent, then the passkey */
    public const string ADD_PASSKEY = 'yorvoid.add_passkey';

    /** A step-up for "step_up.operation" */
    public const string STEP_UP = 'yorvoid.step_up';

    /** Confirming an operation the verifier prepared */
    public const string SIGN_INTENT = 'yorvoid.sign_intent';

    /**
     * @param Duration $window How recent a step-up must be, as for the credentials (5 minutes by default)
     */
    public function __construct(
        private Passkeys $passkeys,
        private Sessions $sessions,
        private Principals $principals,
        private SetupCodes $setupCodes,
        private Invitations $invitations,
        private RecoveryCodes $recoveryCodes,
        private RecoveryLinks $recoveryLinks,
        private StepUp $stepUp,
        private Attempts $attempts,
        private Duration $window,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * Every journey, to give Flows.
     *
     * @return list<Flow>
     */
    public function all(): array
    {
        $passkey = new PasskeyRegistrationStep($this->passkeys);
        $later = new PasskeyRegistrationStep($this->passkeys, skippable: true);
        $codes = new RecoveryCodesStep($this->recoveryCodes);

        return [
            new Flow(self::SIGN_IN, [new PasskeySignInStep($this->passkeys)], [PasskeySignInStep::NAME]),
            new Flow(self::SETUP, [new SetupCodeStep($this->setupCodes, $this->sessions, $this->clock), $passkey, $codes], [SetupCodeStep::NAME, PasskeyRegistrationStep::NAME]),
            new Flow(self::INVITATION, [new InvitationStep($this->invitations, $this->principals, $this->sessions, $this->clock), $passkey, $codes], [InvitationStep::NAME]),
            new Flow(self::RECOVER_WITH_CODE, [new RecoveryCodeStep($this->recoveryCodes, $this->principals, $this->attempts), $later, $codes], [RecoveryCodeStep::NAME]),
            new Flow(self::RECOVER_WITH_LINK, [new RecoveryLinkStep($this->recoveryLinks), $later, $codes], [RecoveryLinkStep::NAME]),
            new Flow(self::ADD_PASSKEY, [new StepUpStep($this->passkeys, $this->stepUp, $this->window, 'yorvoid.credential.add'), $passkey], [PasskeyRegistrationStep::NAME]),
            new Flow(self::STEP_UP, [new StepUpStep($this->passkeys, $this->stepUp, $this->window)], [StepUpStep::NAME]),
            new Flow(self::SIGN_INTENT, [new IntentConfirmationStep()], [IntentConfirmationStep::NAME]),
        ];
    }
}
