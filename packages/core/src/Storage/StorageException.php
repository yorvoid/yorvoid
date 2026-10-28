<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * Storage couldn't do what was asked, for a reason outside the application's code: e.g. another process
 * holds the migrations' lock.
 *
 * @api
 */
final class StorageException extends \RuntimeException implements YorvoIDException
{
}
