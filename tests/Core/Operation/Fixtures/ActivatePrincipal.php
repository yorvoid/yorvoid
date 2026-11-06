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
#[Operation('yorvoid.principal.activate', 'Activate a principal', AuthorizedBy::Operator, inverse: 'yorvoid.principal.suspend')]
final readonly class ActivatePrincipal implements Command
{
    public function __construct(
        public Realm $realm,
        public string $principalId,
    ) {
    }
}
