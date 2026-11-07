<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures\Badges;

use YorvoID\Core\Authorization\AuthorityCheck;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;

/**
 * Every actor's authority is valid: for tests of the decision point, not of authority.
 */
final readonly class ValidAuthority implements AuthorityCheck
{
    public function check(Request $request): Truth
    {
        return Truth::holds('Valid.');
    }
}
