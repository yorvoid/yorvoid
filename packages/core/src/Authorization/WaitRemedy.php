<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * Try again later: e.g. after the cool-down that follows an account recovery.
 *
 * @api
 */
final readonly class WaitRemedy implements Remedy
{
    public function __construct(
        public \DateTimeImmutable $until,
    ) {
    }

    public function name(): string
    {
        return 'wait';
    }

    public function parameters(): array
    {
        return ['until' => $this->until->format(\DATE_ATOM)];
    }
}
