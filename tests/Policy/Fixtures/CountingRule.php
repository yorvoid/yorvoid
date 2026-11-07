<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy\Fixtures;

use YorvoID\Core\Authorization\Request;
use YorvoID\Policy\Rule\Rule;
use YorvoID\Policy\Rule\RuleAnswer;

/**
 * A rule that gives one answer, counting how often it's asked.
 */
final class CountingRule implements Rule
{
    public int $asked = 0;

    public bool $matching = true;

    public function __construct(
        private readonly RuleAnswer $answer,
        private readonly string $name = 'acme.locked',
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function matches(Request $request): bool
    {
        return $this->matching;
    }

    public function decide(Request $request): RuleAnswer
    {
        ++$this->asked;

        return $this->answer;
    }
}
