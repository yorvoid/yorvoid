<?php

declare(strict_types=1);

namespace YorvoID\Flows;

/**
 * One step of a flow (PLT-070): what the person sees, and what they send back. Steps keep what they
 * need in the flow's state, never in properties: one instance serves every flow, and a flow may
 * continue in another request, process or server.
 *
 * @api
 */
interface Step
{
    /**
     * The step's name, unique in its flow, e.g. "passkey": forms and the browser client send it back.
     */
    public function name(): string;

    /**
     * Whether the step is needed. Asked once, when the flow reaches it: a step that isn't needed is
     * passed over for good.
     */
    public function applies(FlowState $state, FlowContext $context): bool;

    /**
     * Prepares the step as it becomes current, and again after an answer it refused: e.g. begins a
     * passkey ceremony, or generates recovery codes. Gives the state with what view() and submit()
     * need.
     */
    public function enter(FlowState $state, FlowContext $context): FlowState;

    /**
     * What to show, from the state alone: templates, the browser client and the template gallery
     * render it.
     */
    public function view(FlowState $state): StepView;

    /**
     * Checks what the person sent, and does what the step is for.
     */
    public function submit(FlowState $state, FlowContext $context, Input $input): StepResult;
}
