<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * An intent ready to be signed on the signing page: the console sends the person to
 * SigningPages::url() with its nonce.
 *
 * @api
 */
final readonly class PreparedIntent
{
    /**
     * @param string $options   The WebAuthn request options for navigator.credentials.get(), as JSON: the intent's
     *                          challenge, the verifier's keys, user verification required
     * @param bool   $bootstrap Whether it adds a key with the bootstrap secret, signed by that key
     */
    public function __construct(
        public Intent $intent,
        public string $options,
        public bool $bootstrap = false,
    ) {
    }
}
