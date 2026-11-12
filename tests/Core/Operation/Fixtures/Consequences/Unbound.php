<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures\Consequences;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * An operation that causes a token, about nobody until its handler says.
 *
 * @implements Command<null>
 */
#[Operation('acme.test.unbound', 'Cause a token for someone found later', AuthorizedBy::Operator, causes: ['yorvoid.token.issue', 'yorvoid.principal.create'])]
final readonly class Unbound implements Command
{
    public function __construct(
        public Realm $realm,
    ) {
    }
}
