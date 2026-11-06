<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow;

use YorvoID\Authn\Setup\ClaimSetup;
use YorvoID\Authn\Setup\InvalidSetupCode;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\InvalidIdentifier;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Secret;
use YorvoID\Flows\FlowContext;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\Input;
use YorvoID\Flows\Problem;
use YorvoID\Flows\Step;
use YorvoID\Flows\StepResult;
use YorvoID\Flows\StepView;
use YorvoID\Notifications\EmailVerification;
use YorvoID\Passkeys\Flow\View\SetupCodeView;

/**
 * Claims a fresh installation with its setup code (ATN-040), and signs the new owner in: inputs
 * "code" and "email". The owner then adds a passkey. With EmailVerification, the owner's address gets a
 * link to confirm it, so that security notifications reach them.
 *
 * @api
 */
final readonly class SetupCodeStep implements Step
{
    public const string NAME = 'setup_code';

    public function __construct(
        private Operations $operations,
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
        return $state;
    }

    public function view(FlowState $state): StepView
    {
        return new SetupCodeView();
    }

    public function submit(FlowState $state, FlowContext $context, Input $input): StepResult
    {
        $email = trim($input->string('email') ?? '');
        $code = $input->string('code') ?? '';

        if ('' === $code) {
            return StepResult::refused(new Problem('yorvoid.flow.setup_code.invalid', field: 'code'));
        }

        try {
            // The owner's address gets its link to confirm it from EmailVerification, as a consequence.
            $signedIn = $this->operations->run(new ClaimSetup($state->realm, Secret::fromString($code), new CreatePrincipal($state->realm, PrincipalKind::Human, email: $email), $context->device, $context->address, $context->deviceCookie), new Anonymous(), $context->origin);
        } catch (InvalidSetupCode) {
            return StepResult::refused(new Problem('yorvoid.flow.setup_code.invalid', field: 'code'));
        } catch (InvalidIdentifier) {
            return StepResult::refused(new Problem('yorvoid.flow.email.invalid', field: 'email'));
        }

        return StepResult::done(signedIn: $signedIn);
    }
}
