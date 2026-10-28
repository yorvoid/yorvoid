<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * A listener of an "-ing" event cancelled the operation; nothing it did was kept. The reason is the
 * listener's, for the person who tried.
 *
 * @api
 */
final class OperationCancelled extends \RuntimeException implements YorvoIDException
{
    public function __construct(
        public readonly string $operation,
        public readonly string $reason,
    ) {
        parent::__construct(\sprintf('The operation "%s" was cancelled: %s', $operation, $reason));
    }
}
