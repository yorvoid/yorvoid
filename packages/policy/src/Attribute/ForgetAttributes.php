<?php

declare(strict_types=1);

namespace YorvoID\Policy\Attribute;

use YorvoID\Core\Authorization\ChangesAuthority;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Removes the security attributes of a deleted principal. Only a deletion causes it (DeletePrincipal),
 * through ForgetAttributesOfDeletedPrincipals.
 *
 * @implements ChangesAuthority<void>
 *
 * @api
 */
#[Operation('yorvoid.attribute.forget_all', 'Remove the security attributes of a deleted principal', AuthorizedBy::Consequence)]
final readonly class ForgetAttributes implements ChangesAuthority, ConcernsPrincipal
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
