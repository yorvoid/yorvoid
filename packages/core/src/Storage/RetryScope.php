<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage;

/**
 * Whether work is running under Transactions::retrying(), and on which attempt: a transaction that
 * conflicts on an attempt other than the last drops what it left waiting for its commit and its end,
 * since the work runs again and leaves them again (D-52). The last attempt's run as after any rollback.
 *
 * @internal
 */
final class RetryScope
{
    public bool $active = false;

    /** On the last attempt: a conflict now is thrown, not run again */
    public bool $final = false;
}
