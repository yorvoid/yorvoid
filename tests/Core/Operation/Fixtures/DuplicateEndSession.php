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
#[Operation('yorvoid.session.end', 'End a session, again', AuthorizedBy::Operator)]
final readonly class DuplicateEndSession implements Command
{
    public function __construct(
        public Realm $realm,
    ) {
    }
}
