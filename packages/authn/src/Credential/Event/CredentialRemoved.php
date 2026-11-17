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
    /**
     * @internal YorvoID dispatches it: its listeners act on it, so an application doesn't make one
     */
    public function __construct(
        public Credential $credential,
        public Origin $origin,
    ) {
    }
}
