<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow\View;

use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\Ceremonies;

/**
 * Confirming a signed operation: the only place the person sees what they sign, so the page shows the
 * summary and every parameter in full.
 *
 * @api
 */
final readonly class IntentConfirmationView implements StepView
{
    /**
     * @param string                $summary    What the operation does, in the person's language
     * @param array<string, string> $parameters Every parameter, as the summary shows it
     * @param string                $options    navigator.credentials.get()'s options, as JSON
     * @param string                $expiresAt  When the operation can't be signed any more, ISO 8601
     */
    public function __construct(
        public string $summary,
        public array $parameters,
        public string $options,
        public string $expiresAt,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['summary' => $this->summary, 'parameters' => $this->parameters, 'options' => Ceremonies::decode($this->options), 'expires_at' => $this->expiresAt];
    }
}
