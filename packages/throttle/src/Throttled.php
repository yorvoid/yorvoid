<?php

declare(strict_types=1);

namespace YorvoID\Throttle;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * Too many attempts for a key: the next one is allowed from $retryAfter. Nothing was counted. Tell the
 * person when to try again, e.g. with a Retry-After header.
 *
 * @api
 */
final class Throttled extends \RuntimeException implements YorvoIDException
{
    /**
     * @param int $seconds How long until then, rounded up
     */
    public function __construct(
        public readonly \DateTimeImmutable $retryAfter,
        public readonly int $seconds,
    ) {
        parent::__construct(\sprintf('Too many attempts. Please try again in %d seconds.', $seconds));
    }
}
