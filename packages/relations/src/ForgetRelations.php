<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Authorization\ChangesAuthority;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Removes every relation of a deleted principal. Only a deletion causes it (DeletePrincipal), through
 * ForgetRelationsOfDeletedPrincipals.
 *
 * @implements ChangesAuthority<void>
 *
 * @api
 */
#[Operation('yorvoid.relation.forget_all', 'Remove every relation of a deleted principal', AuthorizedBy::Consequence)]
final readonly class ForgetRelations implements ChangesAuthority, ConcernsPrincipal
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
