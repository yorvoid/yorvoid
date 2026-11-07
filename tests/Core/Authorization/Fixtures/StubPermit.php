<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\Fixtures;

use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\AuthorizedBy;

/**
 * A permit that gives what it's told, or throws it.
 */
final class StubPermit implements PermitElement
{
    public int $evaluated = 0;

    public function __construct(
        private readonly string $name,
        private readonly Truth|\Throwable $truth,
        private readonly AuthorizedBy $basis = AuthorizedBy::Ability,
        private readonly bool|\Throwable $matches = true,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function basis(): AuthorizedBy
    {
        return $this->basis;
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
