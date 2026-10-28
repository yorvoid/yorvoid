<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * Why the verifier refused a signature or a key change.
 *
 * @api
 */
enum Refusal: string
{
    /** No intent waits under the nonce: it was never prepared, was used, or expired */
    case UnknownIntent = 'unknown_intent';

    /** The intent is of another kind than the call expected, e.g. a key's removal given to verify() */
    case WrongOperation = 'wrong_operation';

    /** The answer isn't a valid signature of the intent: wrong challenge, origin, signature, or not an assertion */
    case InvalidSignature = 'invalid_signature';

    /** The signature isn't from one of the verifier's keys */
    case UnknownKey = 'unknown_key';

    /** The authenticator didn't verify its user */
    case UserNotVerified = 'user_not_verified';

    /** The key to add isn't a usable passkey public key, or is there already */
    case InvalidKey = 'invalid_key';

    /** Removing the key would leave the verifier with none */
    case LastKey = 'last_key';

    /** The bootstrap secret is wrong, used, or there's none */
    case InvalidBootstrapSecret = 'invalid_bootstrap_secret';
}
