<?php

declare(strict_types=1);

namespace YorvoID\Tests\Flows;

use YorvoID\Flows\FlowContext;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\Input;
use YorvoID\Flows\Step;
use YorvoID\Flows\StepResult;
use YorvoID\Flows\StepView;

/**
 * A step that does what the test says, and counts how often it was prepared.
 */
final class TestStep implements Step
{
    /** @var list<FlowContext> */
    public array $entered = [];

    /** @var list<FlowContext> */
    public array $submitted = [];

    /**
     * @param (\Closure(FlowState, FlowContext, Input): StepResult)|null $submit
     * @param (\Closure(FlowState, FlowContext): bool)|null              $applies
     * @param (\Closure(int): void)|null                                 $enter   Told how often it was prepared; may throw
     */
    public function __construct(
        private readonly string $name,
        private readonly ?\Closure $submit = null,
        private readonly ?\Closure $applies = null,
        private readonly ?\Closure $enter = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function applies(FlowState $state, FlowContext $context): bool
    {
        return null === $this->applies || ($this->applies)($state, $context);
    }

    public function enter(FlowState $state, FlowContext $context): FlowState
    {
        $this->entered[] = $context;

        if (null !== $this->enter) {
            ($this->enter)(\count($this->entered));
        }

        return $state->with([$this->name.'.entered' => \count($this->entered)]);
    }

    public function view(FlowState $state): StepView
    {
        return new TestView($this->name, $state->get($this->name.'.entered'));
    }

    public function submit(FlowState $state, FlowContext $context, Input $input): StepResult
    {
        $this->submitted[] = $context;

        return null === $this->submit ? StepResult::done() : ($this->submit)($state, $context, $input);
    }
}
