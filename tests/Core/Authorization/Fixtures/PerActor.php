<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\Fixtures;

use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\AuthorizedBy;

/**
 * A permit that holds, fails or is unknown for each principal as it's told, and fails for any other.
 */
final class PerActor implements PermitElement
{
    public int $asked = 0;

    /**
     * @param array<string, Truth> $truths By principal ID
     */
    public function __construct(
        private readonly array $truths,
        private readonly AuthorizedBy $basis = AuthorizedBy::Ability,
    ) {
    }

    public function name(): string
    {
        return 'acme.per_actor';
    }

    public function basis(): AuthorizedBy
    {
        return $this->basis;
    }

    public function matches(Request $request): bool
    {
        return true;
    }

    public function evaluate(Request $request): Truth
    {
        ++$this->asked;

        return $this->truths[(string) $request->actor->principalId] ?? Truth::fails('Nobody said.');
    }
}
