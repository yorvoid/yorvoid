<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * @api
 */
#[Operation('yorvoid.principal.delete', 'Delete a principal, keeping a tombstone for the security log', ability: 'yorvoid.principals.manage')]
final readonly class DeletePrincipal
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
    ) {
    }
}
