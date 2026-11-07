<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures\Doors;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * A door that opens also while the link home is down: it accepts Permit-offline.
 *
 * @implements Command<string>
 */
#[Operation('acme.door.open', 'Open a door', AuthorizedBy::Ability, ability: 'acme.doors.open', causes: ['acme.door.log'], acceptsOffline: true)]
final readonly class OpenDoor implements Command
{
    public function __construct(
        public Realm $realm,
    ) {
    }
}
