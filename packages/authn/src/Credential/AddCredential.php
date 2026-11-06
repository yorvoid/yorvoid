<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential;

use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\SelfOperation;
use YorvoID\Core\Realm;

/**
 * Adds a credential to the signed-in principal's own account (ATN-027). Packages that keep a kind of
 * credential (passkeys, passwords) run it with their own record, in one transaction.
 *
 * @implements SelfOperation<Credential>
 *
 * @api
 */
#[Operation('yorvoid.credential.add', 'Add a sign-in method to one\'s account', AuthorizedBy::Self, inverse: 'yorvoid.credential.remove')]
final readonly class AddCredential implements SelfOperation
{
    public function __construct(
        public Realm $realm,
        public CredentialKind $kind,
        public string $label,
    ) {
    }

    public function concerns(Actor $actor): bool
    {
        return null !== $actor->principalId;
    }
}
