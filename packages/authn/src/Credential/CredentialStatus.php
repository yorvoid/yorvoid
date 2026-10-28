<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential;

/**
 * @api
 */
enum CredentialStatus: string
{
    case Active = 'active';

    /** It doesn't work, e.g. a passkey suspected of having been copied; its owner removes it */
    case Disabled = 'disabled';
}
