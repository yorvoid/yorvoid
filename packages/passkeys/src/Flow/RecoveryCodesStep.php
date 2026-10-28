<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow;

use YorvoID\Authn\Recovery\GenerateRecoveryCodes;
use YorvoID\Authn\Recovery\RecoveryCodes;
use YorvoID\Core\Secret;
use YorvoID\Flows\FlowContext;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\Input;
use YorvoID\Flows\Problem;
use YorvoID\Flows\Step;
use YorvoID\Flows\StepResult;
use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\View\RecoveryCodesView;

/**
 * Gives the signed-in person recovery codes when their account has none left (ATN-025), and has them
 * confirm they saved them: input "saved". The codes are forgotten once confirmed.
 *
 * @api
 */
final readonly class RecoveryCodesStep implements Step
{
    public const string NAME = 'recovery_codes';

    private const string CODES = self::NAME.'.codes';

    public function __construct(
        private RecoveryCodes $codes,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function applies(FlowState $state, FlowContext $context): bool
    {
        return null !== $context->session && 0 === $this->codes->remaining($context->session->realm, $context->session->principalId);
    }

    /**
     * Generates them once: shown again after a refusal, they're the same.
     */
    public function enter(FlowState $state, FlowContext $context): FlowState
    {
        if ($state->has(self::CODES) || null === $context->session) {
            return $state;
        }

        $codes = $this->codes->generate(new GenerateRecoveryCodes($context->session->realm), $context->session, $context->origin);

        return $state->with([self::CODES => array_map(static fn (Secret $code): string => $code->reveal(), $codes)]);
    }

    public function view(FlowState $state): StepView
    {
        // The codes enter() kept.
        /** @var list<string> $codes */
        $codes = $state->get(self::CODES) ?? [];

        return new RecoveryCodesView($codes);
    }

    public function submit(FlowState $state, FlowContext $context, Input $input): StepResult
    {
        return $input->isChecked('saved') ? StepResult::done(forget: [self::CODES]) : StepResult::refused(new Problem('yorvoid.flow.recovery_codes.not_saved', field: 'saved'));
    }
}
