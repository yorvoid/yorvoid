<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Operation;

#[Operation('yorvoid.log.verify', 'Verify the security log', AuthorizedBy::Operator)]
final readonly class NotACommand
{
}
