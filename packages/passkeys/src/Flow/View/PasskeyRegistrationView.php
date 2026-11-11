<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow\View;

use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\Ceremonies;

/**
 * Adding a passkey, with a label the person chooses.
 *
 * @api
 */
final readonly class PasskeyRegistrationView implements StepView
{
    /**
     * @param string|null $options navigator.credentials.create()'s options, as JSON; null when no one is signed in
     * @param bool        $canSkip Whether the person may add one later, e.g. on a borrowed device: YorvoID's page reads it as "this may be someone else's device", and asks before adding one
     */
    public function __construct(
        public ?string $options,
        public bool $canSkip,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['options' => Ceremonies::decode($this->options), 'can_skip' => $this->canSkip];
    }
}
