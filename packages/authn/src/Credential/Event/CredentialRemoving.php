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
    public function __construct(
        public Credential $credential,
        public Origin $origin,
    ) {
    }
}
