<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow;

use YorvoID\Authn\Recovery\RecoveryLinks;
use YorvoID\Core\Secret;
use YorvoID\Flows\FlowContext;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\Input;
use YorvoID\Flows\Problem;
use YorvoID\Flows\Step;
use YorvoID\Flows\StepResult;
use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\View\RecoveryLinkView;

/**
 * Recovers an account with a recovery link (ATN-030). Start the flow with the link's token as
 * "recovery_link.token"; the person confirms, so an email scanner opening the link doesn't use it up.
 * Every other session ends.
 *
 * @api
 */
final readonly class RecoveryLinkStep implements Step
{
    public const string NAME = 'recovery_link';

    public const string TOKEN = self::NAME.'.token';

    public function __construct(
        private RecoveryLinks $links,
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
        return new RecoveryLinkView();
    }

    public function submit(FlowState $state, FlowContext $context, Input $input): StepResult
    {
        $token = $state->string(self::TOKEN) ?? '';
        $started = '' === $token ? null : $this->links->redeem($state->realm, Secret::fromString($token), $context->origin, $context->device, $context->address, $context->deviceCookie);

        return null === $started ? StepResult::failed(new Problem('yorvoid.flow.recovery_link.not_working')) : StepResult::done(forget: [self::TOKEN], signedIn: $started);
    }
}
