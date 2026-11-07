<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * @implements Command<null>
 */
#[Operation('yorvoid.principal.suspend', 'Suspend a principal', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: 'yorvoid.principals.manage', inverse: 'yorvoid.principal.activate')]
final readonly class SuspendPrincipal implements Command
{
    public function __construct(
        public Realm $realm,
        public string $principalId,
    ) {
    }
}
