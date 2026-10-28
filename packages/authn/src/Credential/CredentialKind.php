<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential;

/**
 * @api
 */
enum CredentialKind: string
{
    case Passkey = 'passkey';

    case Password = 'password';

    /** An authenticator app's codes */
    case Totp = 'totp';

    /** A set of recovery codes */
    case RecoveryCodes = 'recovery_codes';

    /**
     * Whether it signs in on its own; recovery codes recover access, and an authenticator app's codes
     * are a second factor.
     */
    public function signsIn(): bool
    {
        return self::Passkey === $this || self::Password === $this;
    }
}
