<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

/**
 * Why a session ended (SES-011).
 *
 * @api
 */
enum SessionEndReason: string
{
    /** Its principal signed out */
    case SignedOut = 'signed_out';

    /** Its principal ended it from another session, e.g. "sign out everywhere else" */
    case EndedByPrincipal = 'ended_by_principal';

    /** An administrator ended it */
    case EndedByAdministrator = 'ended_by_administrator';

    /** Its principal was suspended or deleted */
    case PrincipalDeactivated = 'principal_deactivated';

    /** A credential of its principal changed or was removed */
    case CredentialChanged = 'credential_changed';

    /** Its principal recovered access, e.g. with a recovery link: whoever else had a session may be why */
    case Recovered = 'recovered';

    /** Its principal said something in a security notification wasn't them ("this wasn't me") */
    case Reported = 'reported';
}
