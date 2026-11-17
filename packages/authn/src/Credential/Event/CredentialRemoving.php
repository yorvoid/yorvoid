<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential\Event;

use YorvoID\Authn\Credential\Credential;
use YorvoID\Core\Event\Origin;

/**
 * A credential is about to be removed, inside the transaction: the package that keeps its secret part
 * (e.g. the passkey's public key) removes that too.
 *
 * @api
 */
final readonly class CredentialRemoving
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
