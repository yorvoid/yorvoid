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
#[Operation('yorvoid.log.verify', 'Verify the security log', AuthorizedBy::Operator)]
final class NoConstructor implements Command
{
    public Realm $realm {
        get => new Realm('acme');
    }
}
