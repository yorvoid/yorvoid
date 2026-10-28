<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow;

use YorvoID\Flows\FlowContext;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\Input;
use YorvoID\Flows\Problem;
use YorvoID\Flows\Step;
use YorvoID\Flows\StepResult;
use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\View\IntentConfirmationView;
use YorvoID\Passkeys\Intent\SigningRequest;

/**
 * Shows an operation the verifier prepared, and has the person sign it (INT-011): input "response".
 * Start the flow with IntentConfirmationStep::data() of the signing request. When it completes, send
 * "intent.nonce" and "intent.assertion" to the verifier: it checks the signature, not this step.
 *
 * @api
 */
final readonly class IntentConfirmationStep implements Step
{
    public const string NAME = 'confirm';

    public const string NONCE = 'intent.nonce';

    public const string ASSERTION = 'intent.assertion';

    /**
     * The flow's data for a signing request.
     *
     * @return array<string, mixed>
     */
    public static function data(SigningRequest $request): array
    {
        return [
            self::NONCE => $request->nonce,
            'intent.summary' => $request->summary->text,
            'intent.parameters' => $request->summary->parameters,
            'intent.expires_at' => $request->summary->expiresAt->format(\DATE_ATOM),
            'intent.options' => $request->options,
        ];
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
        $given = $state->get('intent.parameters');
        $parameters = [];

        foreach (\is_array($given) ? $given : [] as $name => $value) {
            if (\is_string($name)) {
                $parameters[$name] = \is_string($value) ? $value : '';
            }
        }

        return new IntentConfirmationView($state->string('intent.summary') ?? '', $parameters, $state->string('intent.options') ?? '{}', $state->string('intent.expires_at') ?? '');
    }

    public function submit(FlowState $state, FlowContext $context, Input $input): StepResult
    {
        if (null === $context->session) {
            return StepResult::failed(new Problem('yorvoid.flow.signed_out'));
        }

        $assertion = $input->json('response');

        return null === $assertion ? StepResult::refused(new Problem('yorvoid.passkey.not_working', field: 'response')) : StepResult::done([self::ASSERTION => $assertion]);
    }
}
