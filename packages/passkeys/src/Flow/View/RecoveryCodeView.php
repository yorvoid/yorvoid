<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow\View;

use YorvoID\Core\Duration;
use YorvoID\Flows\StepView;

/**
 * Recovering with a recovery code: the account's email address, and one of its codes.
 *
 * @api
 */
final readonly class RecoveryCodeView implements StepView
{
    /**
     * @param Duration|null $holds How long sensitive changes wait once someone gets back in this way; null if they don't
     */
    public function __construct(
        public ?Duration $holds = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['holds' => $this->holds?->seconds];
    }
}
