<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow;

use Psr\Clock\ClockInterface;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\Setup\InvalidSetupCode;
use YorvoID\Authn\Setup\SetupCodes;
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
use YorvoID\Passkeys\Flow\View\SetupCodeView;

/**
 * Claims a fresh installation with its setup code (ATN-040), and signs the new owner in: inputs
 * "code" and "email". The owner then adds a passkey.
 *
 * @api
 */
final readonly class SetupCodeStep implements Step
{
    public const string NAME = 'setup_code';

    public function __construct(
        private SetupCodes $setupCodes,
        private Sessions $sessions,
        private ClockInterface $clock,
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
            $owner = $this->setupCodes->claim($state->realm, Secret::fromString($code), new CreatePrincipal($state->realm, PrincipalKind::Human, email: $email), $context->origin, $context->address);
        } catch (InvalidSetupCode) {
            return StepResult::refused(new Problem('yorvoid.flow.setup_code.invalid', field: 'code'));
        } catch (InvalidIdentifier) {
            return StepResult::refused(new Problem('yorvoid.flow.email.invalid', field: 'email'));
        }

        return StepResult::done(signedIn: $this->sessions->start(new StartSession($state->realm, $owner->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::SetupCode, $this->clock->now())), $context->device, $context->address, $context->deviceCookie), $context->origin));
    }
}
