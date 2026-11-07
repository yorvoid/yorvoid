<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow;

use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Operation\Operations;
use YorvoID\Flows\FlowContext;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\Input;
use YorvoID\Flows\Step;
use YorvoID\Flows\StepResult;
use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\View\PasskeySignInView;
use YorvoID\Passkeys\PasskeyRefused;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Passkeys\Refusal;
use YorvoID\Passkeys\SignInWithPasskey;

/**
 * Signs in with a passkey (ATN-002): input "response", the browser's answer.
 *
 * @api
 */
final readonly class PasskeySignInStep implements Step
{
    public const string NAME = 'passkey';

    public function __construct(
        private Operations $operations,
        private Passkeys $passkeys,
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
        return Ceremonies::keep($state, self::NAME, $this->passkeys->beginSignIn($state->realm));
    }

    public function view(FlowState $state): StepView
    {
        return new PasskeySignInView(Ceremonies::options($state, self::NAME) ?? '{}');
    }

    public function submit(FlowState $state, FlowContext $context, Input $input): StepResult
    {
        try {
            return StepResult::done(signedIn: $this->operations->run(new SignInWithPasskey($state->realm, Ceremonies::id($state, self::NAME), $input->json('response') ?? '', $context->device, $context->address, $context->deviceCookie), new Anonymous(), $context->origin));
        } catch (PasskeyRefused $refused) {
            // Only an unknown passkey is told apart: the browser then tells its provider (ATN-008).
            return StepResult::refused(Ceremonies::problem($refused, Refusal::UnknownPasskey === $refused->refusal));
        }
    }
}
