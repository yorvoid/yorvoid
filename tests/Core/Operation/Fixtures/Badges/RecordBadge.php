<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures\Badges;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * @implements Command<string>
 */
#[Operation('acme.badge.record', 'Record a badge in the register', AuthorizedBy::Consequence)]
final readonly class RecordBadge implements Command
{
    public function __construct(
        public Realm $realm,
    ) {
    }
}
