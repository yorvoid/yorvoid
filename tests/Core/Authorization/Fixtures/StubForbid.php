<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\Fixtures;

use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;

/**
 * A forbid that gives what it's told, or throws it.
 */
final class StubForbid implements ForbidElement
{
    public int $evaluated = 0;

    public function __construct(
        private readonly string $name,
        private readonly Truth|\Throwable $truth,
        private readonly bool|\Throwable $matches = true,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function matches(Request $request): bool
    {
        return $this->matches instanceof \Throwable ? throw $this->matches : $this->matches;
    }

    public function evaluate(Request $request): Truth
    {
        ++$this->evaluated;

        return $this->truth instanceof \Throwable ? throw $this->truth : $this->truth;
    }
}
