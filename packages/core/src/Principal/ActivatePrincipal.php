<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * @api
 */
#[Operation('yorvoid.principal.activate', 'Activate a principal (an invited one, or a suspended one again)', ability: 'yorvoid.principals.manage', inverse: 'yorvoid.principal.suspend')]
final readonly class ActivatePrincipal
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
    ) {
    }
}
