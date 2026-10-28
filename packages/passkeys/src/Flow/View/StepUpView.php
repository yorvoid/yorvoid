<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow\View;

use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\Ceremonies;

/**
 * Confirming it's the person, with a passkey, before an operation.
 *
 * @api
 */
final readonly class StepUpView implements StepView
{
    /**
     * @param string|null $options   navigator.credentials.get()'s options, as JSON; null when no one is signed in
     * @param string|null $operation The operation it's for, e.g. "yorvoid.credential.add"
     */
    public function __construct(
        public ?string $options,
        public ?string $operation,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['options' => Ceremonies::decode($this->options), 'operation' => $this->operation];
    }
}
