<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Removes every credential of a deleted principal, whatever its status, with no session or step-up.
 * Only a deletion causes it (DeletePrincipal), through ForgetCredentialsOfDeletedPrincipals.
 *
 * @implements Command<int>
 *
 * @api
 */
#[Operation('yorvoid.credential.forget_all', 'Remove every credential of a deleted principal', AuthorizedBy::Consequence)]
final readonly class ForgetCredentials implements Command, ConcernsPrincipal
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
    ) {
    }

    public function principal(): PrincipalId
    {
        return $this->principalId;
    }
}
