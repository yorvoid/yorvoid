<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow;

use YorvoID\Authn\StepUp\StepUpRequired;
use YorvoID\Core\Operation\Operations;
use YorvoID\Flows\FlowContext;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\Input;
use YorvoID\Flows\Problem;
use YorvoID\Flows\Step;
use YorvoID\Flows\StepResult;
use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\View\PasskeyRegistrationView;
use YorvoID\Passkeys\PasskeyRefused;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Passkeys\RegisterPasskey;

/**
 * Adds a passkey to the signed-in person's account: inputs "response", the browser's answer, and
 * "label". Keeps the new credential's ID as "passkey_registration.credential".
 *
 * @api
 */
final readonly class PasskeyRegistrationStep implements Step
{
    public const string NAME = 'passkey_registration';

    /**
     * @param bool $skippable Whether the person may add one later (Input::SKIP), e.g. after recovering on a borrowed device
     */
    public function __construct(
        private Operations $operations,
        private Passkeys $passkeys,
        private bool $skippable = false,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function applies(FlowState $state, FlowContext $context): bool
    {
        return true;
    }

    public function enter(FlowState $state, FlowContext $context): FlowState
    {
        return null === $context->session ? $state : Ceremonies::keep($state, self::NAME, $this->passkeys->beginRegistration($context->session));
    }

    public function view(FlowState $state): StepView
    {
        return new PasskeyRegistrationView(Ceremonies::options($state, self::NAME), $this->skippable);
    }

    public function submit(FlowState $state, FlowContext $context, Input $input): StepResult
    {
        if ($this->skippable && Input::SKIP === $input->action()) {
            return StepResult::done();
        }

        if (null === $context->session) {
            return StepResult::failed(new Problem('yorvoid.flow.signed_out'));
        }

        try {
            $credential = $this->operations->run(new RegisterPasskey($context->session->realm, Ceremonies::id($state, self::NAME), $input->json('response') ?? '', $input->string('label') ?? ''), $context->session, $context->origin);
        } catch (PasskeyRefused $refused) {
            return StepResult::refused(Ceremonies::problem($refused));
        } catch (StepUpRequired) {
            // The proof lapsed at the authenticator: confirm again, where the flow has a step-up.
            return StepResult::reopen(new Problem('yorvoid.flow.confirm_again'), StepUpStep::NAME);
        }

        return StepResult::done([self::NAME.'.credential' => (string) $credential->id]);
    }
}
