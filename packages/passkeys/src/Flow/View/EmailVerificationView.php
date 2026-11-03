<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow\View;

use YorvoID\Flows\StepView;

/**
 * Confirming an email address.
 *
 * @api
 */
final readonly class EmailVerificationView implements StepView
{
    /**
     * @param string|null $email The address to confirm; null if the link doesn't work any more
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
