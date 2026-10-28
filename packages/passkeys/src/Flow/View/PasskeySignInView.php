<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow\View;

use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\Ceremonies;

/**
 * Signing in with a passkey: offered in the username field's autofill, and with a button.
 *
 * @api
 */
final readonly class PasskeySignInView implements StepView
{
    /**
     * @param string $options navigator.credentials.get()'s options, as JSON
     */
    public function __construct(
        public string $options,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['options' => Ceremonies::decode($this->options)];
    }
}
