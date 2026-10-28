<?php

declare(strict_types=1);

namespace YorvoID\Authn\Token;

use YorvoID\Core\Principal\Principal;

/**
 * @internal
 */
final readonly class WorkingToken
{
    public function __construct(
        public OneTimeToken $token,
        public Principal $principal,
    ) {
    }
}
