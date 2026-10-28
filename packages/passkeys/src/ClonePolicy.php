<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

/**
 * What happens when a passkey's signature counter doesn't go up (ATN-006): a sign that the key may have
 * been copied. Passkeys that sync between devices always report 0 and are never suspected.
 *
 * @api
 */
enum ClonePolicy: string
{
    /** Refuse this sign-in, and record it */
    case RefuseSignIn = 'refuse_sign_in';

    /** Refuse it and disable the passkey: its owner signs in another way and removes it */
    case DisablePasskey = 'disable_passkey';

    /** Let it through, and record it */
    case Allow = 'allow';
}
