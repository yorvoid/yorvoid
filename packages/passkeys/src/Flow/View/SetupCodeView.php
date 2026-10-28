<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow\View;

use YorvoID\Flows\StepView;

/**
 * Claiming a fresh installation: the setup code from the server, and the owner's email address.
 *
 * @api
 */
final readonly class SetupCodeView implements StepView
{
    public function jsonSerialize(): array
    {
        return [];
    }
}
