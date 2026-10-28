<?php

declare(strict_types=1);

namespace YorvoID\Authn\Token\Event;

use YorvoID\Authn\Token\IssuedToken;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\PrincipalId;

/**
 * A one-time token was issued, and it committed. Send it now: it can't be shown again.
 *
 * @api
 */
final readonly class OneTimeTokenIssued
{
    public function __construct(
        public IssuedToken $issued,
        public Origin $origin,
        public ?PrincipalId $actor,
    ) {
    }
}
