<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * An intent the verifier prepared and waits for a signature of, kept by its nonce.
 *
 * @api
 */
final readonly class PendingIntent
{
    /**
     * @param bool $bootstrap Whether it adds the first key, signed by that key, with the bootstrap secret
     */
    public function __construct(
        public Intent $intent,
        public bool $bootstrap,
    ) {
    }
}
