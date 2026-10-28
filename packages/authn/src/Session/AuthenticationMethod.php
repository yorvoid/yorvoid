<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

/**
 * How someone proved who they are.
 *
 * @api
 */
enum AuthenticationMethod: string
{
    /** A passkey, with user verification (a PIN or biometric on the device) */
    case Passkey = 'passkey';

    case Password = 'password';

    /** A code from an authenticator app */
    case Totp = 'totp';

    /** One of the recovery codes they saved */
    case RecoveryCode = 'recovery_code';

    /** A setup code, e.g. the one printed when a server is installed */
    case SetupCode = 'setup_code';

    /** A link sent to their email address */
    case EmailLink = 'email_link';

    /** A recovery link created by an operator on the server */
    case RecoveryLink = 'recovery_link';

    /** An identity provider, through OpenID Connect or SAML */
    case Federated = 'federated';
}
