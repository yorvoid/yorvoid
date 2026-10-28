<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow;

use YorvoID\Authn\Session\Session;
use YorvoID\Authn\StepUp\AuthenticationRequirement;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\StepUp\StepUpRequired;
use YorvoID\Core\Duration;
use YorvoID\Flows\FlowContext;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\Input;
use YorvoID\Flows\Problem;
use YorvoID\Flows\Step;
use YorvoID\Flows\StepResult;
use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\View\StepUpView;
use YorvoID\Passkeys\PasskeyRefused;
use YorvoID\Passkeys\Passkeys;

/**
 * Confirms it's the person, with a passkey, for an operation (ATN-028): input "response". Only when the
 * session hasn't proved it recently enough. The operation and whether it needs two factors come from
 * the flow's data, "step_up.operation" and "step_up.multi_factor", or else the step's own.
 *
 * @api
 */
final readonly class StepUpStep implements Step
{
    public const string NAME = 'step_up';

    /**
     * @param Duration    $window    How recent the proof must be
     * @param string|null $operation The operation, when the flow's data doesn't name one
     */
    public function __construct(
        private Passkeys $passkeys,
        private StepUp $stepUp,
        private Duration $window,
        private ?string $operation = null,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Unless the session proved it recently enough. Without a session it applies, and refuses: a
     * step-up flow never completes without a proof.
     */
    public function applies(FlowState $state, FlowContext $context): bool
    {
        return null === $context->session || !$this->isMet($context->session, $this->requirement($state));
    }

    public function enter(FlowState $state, FlowContext $context): FlowState
    {
        return null === $context->session ? $state : Ceremonies::keep($state, self::NAME, $this->passkeys->beginStepUp($context->session, $this->requirement($state)));
    }

    public function view(FlowState $state): StepView
    {
        return new StepUpView(Ceremonies::options($state, self::NAME), $this->requirement($state)->operation);
    }

    public function submit(FlowState $state, FlowContext $context, Input $input): StepResult
    {
        if (null === $context->session) {
            return StepResult::failed(new Problem('yorvoid.flow.signed_out'));
        }

        try {
            return StepResult::done(renewedToken: $this->passkeys->finishStepUp($context->session, Ceremonies::id($state, self::NAME), $input->json('response') ?? '', $context->origin));
        } catch (PasskeyRefused $refused) {
            return StepResult::refused(Ceremonies::problem($refused));
        }
    }

    private function requirement(FlowState $state): AuthenticationRequirement
    {
        return new AuthenticationRequirement($this->window, true === $state->get(self::NAME.'.multi_factor'), operation: $state->string(self::NAME.'.operation') ?? $this->operation);
    }

    private function isMet(Session $session, AuthenticationRequirement $requirement): bool
    {
        try {
            $this->stepUp->require($session, $requirement);

            return true;
        } catch (StepUpRequired) {
            return false;
        }
    }
}
