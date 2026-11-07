<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\Fixtures;

use YorvoID\Core\Authorization\OfflineDecider;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;

final class StubOfflineDecider implements OfflineDecider
{
    public int $asked = 0;

    public function __construct(
        private readonly Truth|\Throwable $answer,
    ) {
    }

    public function decide(Request $request): Truth
    {
        ++$this->asked;

        return $this->answer instanceof \Throwable ? throw $this->answer : $this->answer;
    }
}
