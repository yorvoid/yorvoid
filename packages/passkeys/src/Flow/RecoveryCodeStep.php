<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow;

use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Recovery\RecoveryCodes;
use YorvoID\Authn\Session\InactivePrincipal;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Secret;
use YorvoID\Flows\FlowContext;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\Input;
use YorvoID\Flows\Problem;
use YorvoID\Flows\Step;
use YorvoID\Flows\StepResult;
use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\View\RecoveryCodeView;
use YorvoID\Passkeys\Refusal;

/**
 * Recovers an account with one of its recovery codes (ATN-026): inputs "email" and "code". Every other
 * session ends. The answer is the same whether there's no such account or the code is wrong.
 *
 * @api
 */
final readonly class RecoveryCodeStep implements Step
{
    public const string NAME = 'recovery_code';

    public function __construct(
        private RecoveryCodes $codes,
        private Principals $principals,
        private Attempts $attempts,
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
        return new RecoveryCodeView();
    }

    public function submit(FlowState $state, FlowContext $context, Input $input): StepResult
    {
        $email = $input->string('email');
        $code = $input->string('code') ?? '';
        $principal = null === $email ? null : $this->principals->findByIdentifier($state->realm, IdentifierKind::Email, $email);

        // Attempts on accounts that don't exist count too, against the address's limit.
        if (null === $principal) {
            $this->attempts->count('recovery_code', $state->realm, null, $context->address);
        }

        try {
            $started = null === $principal || '' === $code ? null : $this->codes->redeem($state->realm, $principal->id, Secret::fromString($code), $context->origin, $context->device, $context->address, $context->deviceCookie);
        } catch (InactivePrincipal) {
            return StepResult::refused(new Problem(Refusal::PrincipalInactive->messageId()));
        }

        return null === $started ? StepResult::refused(new Problem('yorvoid.flow.recovery_code.invalid', field: 'code')) : StepResult::done(signedIn: $started);
    }
}
