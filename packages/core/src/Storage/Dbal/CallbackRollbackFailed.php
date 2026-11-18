<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\Dbal;

/**
 * A callback after a commit failed, and rolling back what it left failed too: the callback's failure
 * is the previous exception, the rollback's is $rollback. The connection was closed (D-43).
 *
 * @internal
 */
final class CallbackRollbackFailed extends \RuntimeException
{
    public function __construct(\Throwable $failure, public readonly \Throwable $rollback)
    {
        parent::__construct(\sprintf('%s; rolling back what it left failed too: %s', $failure->getMessage(), $rollback->getMessage()), previous: $failure);
    }
}
