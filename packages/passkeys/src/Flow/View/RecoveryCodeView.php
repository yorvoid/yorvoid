<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow\View;

use YorvoID\Flows\StepView;

/**
 * Recovering with a recovery code: the account's email address, and one of its codes.
 *
 * @api
 */
final readonly class RecoveryCodeView implements StepView
{
    public function jsonSerialize(): array
    {
        return [];
    }
}
