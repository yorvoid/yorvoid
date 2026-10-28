<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential;

use YorvoID\Core\Exception\YorvoIDException;
use YorvoID\Core\Realm;

/**
 * There's no such credential on the signed-in principal's account.
 *
 * @api
 */
final class UnknownCredential extends \RuntimeException implements YorvoIDException
{
    public function __construct(
        public readonly Realm $realm,
        public readonly CredentialId $id,
    ) {
        parent::__construct(\sprintf('There is no credential %s on this account.', $id));
    }
}
