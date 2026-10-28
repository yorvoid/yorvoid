<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential\Event;

use YorvoID\Authn\Credential\Credential;
use YorvoID\Core\Event\Origin;

/**
 * A credential was added to its principal's account, and it committed: tell them on every verified channel (ATN-027).
 *
 * @api
 */
final readonly class CredentialAdded
{
    public function __construct(
        public Credential $credential,
        public Origin $origin,
    ) {
    }
}
