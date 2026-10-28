<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * @api
 */
#[Operation('yorvoid.principal.suspend', 'Suspend a principal until it is activated again', ability: 'yorvoid.principals.manage', inverse: 'yorvoid.principal.activate')]
final readonly class SuspendPrincipal
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
    ) {
    }
}
