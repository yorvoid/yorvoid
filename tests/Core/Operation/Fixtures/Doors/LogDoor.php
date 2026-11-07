<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures\Doors;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * @implements Command<string>
 */
#[Operation('acme.door.log', 'Log a door opening', AuthorizedBy::Consequence)]
final readonly class LogDoor implements Command
{
    public function __construct(
        public Realm $realm,
    ) {
    }
}
