<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * A store couldn't be reached: the connection was refused or lost, it timed out, or a failover is in
 * progress (XP-02). Not an error in the data, nor data that's missing: a decision that needed the store
 * is Indeterminate with the reason store_unreachable. A store of your own throws it in those cases.
 *
 * @api
 */
final class StoreUnreachable extends \RuntimeException implements YorvoIDException
{
}
