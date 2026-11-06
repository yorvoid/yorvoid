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
#[Operation('acme.badge.scan', 'Scan a badge at the door', AuthorizedBy::Authentication)]
final readonly class ScanBadge implements Command
{
    public function __construct(
        public Realm $realm,
        public string $code,
    ) {
    }
}
