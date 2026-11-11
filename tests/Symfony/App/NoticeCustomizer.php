<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use YorvoID\Flows\Flow;
use YorvoID\Flows\FlowContext;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\Input;
use YorvoID\Flows\Notice;
use YorvoID\Flows\Step;
use YorvoID\Flows\StepResult;
use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\PasskeyFlows;
use YorvoID\Symfony\Flow\FlowCustomizer;

/**
 * An application's step after a step-up that leaves a notice of its own: the flow's notice is said,
 * not the step-up's.
 */
final class NoticeCustomizer implements Step, StepView, FlowCustomizer
{
    public function customize(Flow $flow): Flow
    {
        return PasskeyFlows::STEP_UP === $flow->name ? $flow->withStepAfter('step_up', $this) : $flow;
    }

    public function name(): string
    {
        return 'acme_noted';
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
        return StepResult::done(notice: new Notice('acme.noted'));
    }

    public function jsonSerialize(): array
    {
        return [];
    }
}
