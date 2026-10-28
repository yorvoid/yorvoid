<?php

declare(strict_types=1);

namespace YorvoID\Authn\Invitation;

use YorvoID\Authn\Token\IssuedToken;
use YorvoID\Core\Principal\Principal;

/**
 * An invited principal, and the link's token to send them. The token is shown only now.
 *
 * @api
 */
final readonly class Invitation
{
    public function __construct(
        public Principal $principal,
        public IssuedToken $token,
    ) {
    }
}
