<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Takes every role from a deleted principal. Only a deletion causes it (DeletePrincipal), through
 * ForgetRolesOfDeletedPrincipals.
 *
 * @implements Command<void>
 *
 * @api
 */
#[Operation('yorvoid.role.forget_all', 'Take every role from a deleted principal', AuthorizedBy::Consequence)]
final readonly class ForgetRoles implements Command, ConcernsPrincipal
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
