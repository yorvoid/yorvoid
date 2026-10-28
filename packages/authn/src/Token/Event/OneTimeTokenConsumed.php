<?php

declare(strict_types=1);

namespace YorvoID\Authn\Token\Event;

use YorvoID\Authn\Token\OneTimeToken;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\PrincipalId;

/**
 * A one-time token was used, and it committed. It, and the principal's other tokens for the purpose, no longer work.
 *
 * @api
 */
final readonly class OneTimeTokenConsumed
{
    public function __construct(
        public OneTimeToken $token,
        public Origin $origin,
        public ?PrincipalId $actor,
    ) {
    }
}
