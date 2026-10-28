<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures;

use YorvoID\Core\Operation\Operation;

#[Operation('yorvoid.log.verify', 'Verify the security log')]
final readonly class NoConstructor
{
}
