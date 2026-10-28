<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow\View;

use YorvoID\Flows\StepView;

/**
 * Accepting an invitation.
 *
 * @api
 */
final readonly class InvitationView implements StepView
{
    /**
     * @param string|null $email The address invited; null if the invitation doesn't work any more
     */
    public function __construct(
        public ?string $email,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['email' => $this->email];
    }
}
