<?php

declare(strict_types=1);

namespace YorvoID\Authn\Token;

use YorvoID\Core\Secret;

/**
 * A new one-time token, and what to send: "selector.verifier". It's shown only now; YorvoID keeps the
 * verifier only as a hash.
 *
 * @api
 */
final readonly class IssuedToken
{
    public function __construct(
        public OneTimeToken $token,
        public Secret $secret,
    ) {
    }
}
