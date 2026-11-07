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
#[Operation('yorvoid.identifier.set', 'Set one\'s own identifier', AuthorizedBy::Self)]
final readonly class SelfWithoutConcerns implements Command
{
    public function __construct(
        public Realm $realm,
    ) {
    }
}
