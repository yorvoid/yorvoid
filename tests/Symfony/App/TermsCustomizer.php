<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use YorvoID\Flows\Flow;
use YorvoID\Flows\FlowContext;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\Input;
use YorvoID\Flows\Problem;
use YorvoID\Flows\Step;
use YorvoID\Flows\StepResult;
use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\PasskeyFlows;

/**
 * An application asking people to accept its terms after they sign in.
 */
final class TermsCustomizer implements Step, StepView, \YorvoID\Symfony\Flow\FlowCustomizer
{
    public function customize(Flow $flow): Flow
    {
        return PasskeyFlows::SIGN_IN === $flow->name ? $flow->withStepAfter('passkey', $this) : $flow;
    }

    public function name(): string
    {
        return 'terms';
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
        return $this;
    }

    public function submit(FlowState $state, FlowContext $context, Input $input): StepResult
    {
        return $input->isChecked('accepted') ? StepResult::done(['terms.accepted' => true]) : StepResult::refused(new Problem('acme.terms.required', field: 'accepted'));
    }

    public function jsonSerialize(): array
    {
        return ['version' => 3];
    }
}
