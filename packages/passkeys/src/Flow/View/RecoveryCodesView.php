<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow\View;

use YorvoID\Flows\StepView;

/**
 * New recovery codes, shown once, for the person to save; they confirm they did.
 *
 * @api
 */
final readonly class RecoveryCodesView implements StepView
{
    /**
     * @param list<string> $codes
     */
    public function __construct(
        public array $codes,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['codes' => $this->codes];
    }
}
