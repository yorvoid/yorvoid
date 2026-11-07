<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * @implements Command<Principal>
 *
 * @api
 */
#[Operation('yorvoid.principal.suspend', 'Suspend a principal until it is activated again', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: 'yorvoid.principals.manage', inverse: 'yorvoid.principal.activate', causes: ['yorvoid.session.end_all'])]
final readonly class SuspendPrincipal implements Command
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
    ) {
    }
}
