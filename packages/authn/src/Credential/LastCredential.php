<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * It's the principal's last way to sign in; removing it would lock them out (ATN-005). Add another
 * first.
 *
 * @api
 */
final class LastCredential extends \RuntimeException implements YorvoIDException
{
    public function __construct(
        public readonly Credential $credential,
    ) {
        parent::__construct('This is your last way to sign in. Add another before removing it.');
    }
}
