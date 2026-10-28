<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential\Event;

use YorvoID\Authn\Credential\Credential;
use YorvoID\Core\Event\Origin;

/**
 * A credential was removed, and it committed.
 *
 * @api
 */
final readonly class CredentialRemoved
{
    public function __construct(
        public Credential $credential,
        public Origin $origin,
    ) {
    }
}
