<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier\Storage;

/**
 * The verifier's storage can't be read or written, or holds something the verifier didn't write.
 *
 * @api
 */
final class StorageError extends \RuntimeException
{
}
