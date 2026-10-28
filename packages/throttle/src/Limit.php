<?php

declare(strict_types=1);

namespace YorvoID\Throttle;

use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;

/**
 * At most so many attempts per period, e.g. 10 per 15 minutes. All of them may come at once; after
 * that, attempts are allowed again at the steady rate (one every 90 seconds, here), not all at the end of
 * the period: a limit slows attempts down rather than locking anyone out for long.
 *
 * @api
 */
final readonly class Limit
{
    public function __construct(
        public int $attempts,
        public Duration $per,
    ) {
        if ($attempts < 1) {
            throw new IntegratorError(\sprintf('A limit of %d attempts allows nothing.', $attempts), 'Allow at least 1 attempt per period.', 'concepts/throttling.md#limits');
        }
    }

    /**
     * How long, in milliseconds, each attempt takes up: the period shared out, rounded up.
     */
    public function interval(): int
    {
        return intdiv($this->per->seconds * 1000 + $this->attempts - 1, $this->attempts);
    }
}
