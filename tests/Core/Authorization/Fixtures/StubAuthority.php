<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\Fixtures;

use YorvoID\Core\Authorization\AuthorityCheck;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;

final readonly class StubAuthority implements AuthorityCheck
{
    public function __construct(
        private Truth|\Throwable $truth,
    ) {
    }

    public function check(Request $request): Truth
    {
        return $this->truth instanceof \Throwable ? throw $this->truth : $this->truth;
    }
}
