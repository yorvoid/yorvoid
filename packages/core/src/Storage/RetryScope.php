<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage;

/**
 * Whether work is running under Transactions::retrying(): then a transaction that conflicts drops what
 * it left waiting for its commit and its end, since the work runs again and leaves them again (D-52).
 *
 * @internal
 */
final class RetryScope
{
    public bool $active = false;
}
