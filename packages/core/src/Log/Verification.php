<?php

declare(strict_types=1);

namespace YorvoID\Core\Log;

/**
 * The result of checking a realm's security log.
 *
 * @api
 */
final readonly class Verification
{
    public function __construct(
        public int $entriesChecked,
        public ?int $brokenAt = null,
        public ?string $problem = null,
    ) {
    }

    public function isIntact(): bool
    {
        return null === $this->problem;
    }
}
