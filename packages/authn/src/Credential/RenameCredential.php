<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential;

use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\SelfOperation;
use YorvoID\Core\Realm;

/**
 * @implements SelfOperation<Credential>
 *
 * @api
 */
#[Operation('yorvoid.credential.rename', 'Rename a sign-in method', AuthorizedBy::Self)]
final readonly class RenameCredential implements SelfOperation
{
    public function __construct(
        public Realm $realm,
        public CredentialId $credentialId,
        public string $label,
    ) {
    }

    public function concerns(Actor $actor): bool
    {
        return null !== $actor->principalId;
    }
}
