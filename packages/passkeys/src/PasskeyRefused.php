<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * A passkey registration or sign-in was refused; nothing changed. The message is for the person;
 * $refusal says why, for the application and its logs.
 *
 * @api
 */
final class PasskeyRefused extends \RuntimeException implements YorvoIDException
{
    public function __construct(
        public readonly Refusal $refusal,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($refusal->message(), previous: $previous);
    }
}
