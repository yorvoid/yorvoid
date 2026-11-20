<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * Two transactions got in each other's way: a deadlock, or a serialization failure, and the database
 * rolled one back (D-52). Nothing of it is committed, and running the same work again usually
 * succeeds. YorvoID retries its own operations (Transactions::retrying()); inside a transaction of the
 * application's, the application retries, e.g. Messenger's retry, or a 503 with Retry-After.
 *
 * @api
 */
final class RetryableConflict extends \RuntimeException implements YorvoIDException
{
    public function __construct(\Throwable $previous, int $attempts = 1)
    {
        parent::__construct(\sprintf('The work conflicted with another transaction%s: run it again.', 1 === $attempts ? '' : \sprintf(' %d times', $attempts)), previous: $previous);
    }
}
