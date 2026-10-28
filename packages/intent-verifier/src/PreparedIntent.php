<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * An intent ready to be signed: what the console needs to ask the browser for a signature.
 *
 * @api
 */
final readonly class PreparedIntent
{
    /**
     * @param string $options The WebAuthn request options for navigator.credentials.get(), as JSON: the intent's
     *                        challenge, the verifier's keys, user verification required
     */
    public function __construct(
        public Intent $intent,
        public string $options,
    ) {
    }
}
