<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

/**
 * Whether the authenticator must check that it's its owner using it (a PIN, a fingerprint, a face).
 *
 * @api
 */
enum UserVerification: string
{
    /**
     * Required, and checked by YorvoID on every registration and sign-in, whatever the browser or the
     * options said (ATN-003). The passkey is then two factors: something you have, unlocked by something
     * you know or are.
     */
    case Required = 'required';

    /**
     * Asked for but not required: a passkey used without it counts as one factor only (NIST SP
     * 800-63B-4, appendix B), and the session records that.
     */
    case Preferred = 'preferred';
}
