<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow;

use YorvoID\Authn\Identifier\EmailVerification;
use YorvoID\Core\Secret;
use YorvoID\Flows\FlowContext;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\Input;
use YorvoID\Flows\Problem;
use YorvoID\Flows\Step;
use YorvoID\Flows\StepResult;
use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\View\EmailVerificationView;

/**
 * Confirms an email address with the link sent to it. Start the flow with the link's token as
 * "verify_email.token"; the person confirms, so an email scanner opening the link doesn't use it up. It
 * doesn't sign anyone in.
 *
 * @api
 */
final readonly class EmailVerificationStep implements Step
{
    public const string NAME = 'verify_email';

    public const string TOKEN = self::NAME.'.token';

    private const string EMAIL = self::NAME.'.email';

    public function __construct(
        private EmailVerification $verification,
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
        $token = $state->string(self::TOKEN) ?? '';
        $email = '' === $token ? null : $this->verification->peek($state->realm, Secret::fromString($token));

        return $state->with([self::EMAIL => $email?->value]);
    }

    public function view(FlowState $state): StepView
    {
        return new EmailVerificationView($state->string(self::EMAIL));
    }

    public function submit(FlowState $state, FlowContext $context, Input $input): StepResult
    {
        $token = $state->string(self::TOKEN) ?? '';
        $verified = '' === $token ? null : $this->verification->confirm($state->realm, Secret::fromString($token), $context->origin);

        if (null === $verified) {
            return StepResult::failed(new Problem('yorvoid.flow.verify_email.not_working'));
        }

        return StepResult::done(forget: [self::TOKEN]);
    }
}
