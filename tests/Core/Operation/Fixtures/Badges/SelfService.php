<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures\Badges;

use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\SelfServicePolicy;

/**
 * Lets anyone change their own account: what to show for it isn't what these tests are about.
 */
final readonly class SelfService implements SelfServicePolicy
{
    public function decide(OperationDescription $operation, Command $command, Actor $actor): Truth
    {
        return Truth::holds('Their own.');
    }
}
