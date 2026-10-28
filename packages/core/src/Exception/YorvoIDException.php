<?php

declare(strict_types=1);

namespace YorvoID\Core\Exception;

/**
 * Every exception YorvoID throws implements this, so applications can catch all of them in one place.
 *
 * @api
 */
interface YorvoIDException extends \Throwable
{
}
