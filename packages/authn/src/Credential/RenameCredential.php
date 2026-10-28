<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential;

use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * @api
 */
#[Operation('yorvoid.credential.rename', 'Rename a sign-in method')]
final readonly class RenameCredential
{
    public function __construct(
        public Realm $realm,
        public CredentialId $credentialId,
        public string $label,
    ) {
    }
}
