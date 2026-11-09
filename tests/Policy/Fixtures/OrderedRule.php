<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy\Fixtures;

use YorvoID\Core\Authorization\OrderedFact;
use YorvoID\Core\Authorization\Request;
use YorvoID\Policy\Ordered\OrderedCondition;
use YorvoID\Policy\Ordered\OrderedConditions;
use YorvoID\Policy\Rule\Rule;
use YorvoID\Policy\Rule\RuleAnswer;

/**
 * A forbid or a permit rule on an ordered fact, e.g. "readiness ≥ 2", answering with OrderedConditions.
 */
final readonly class OrderedRule implements Rule
{
    public function __construct(
        private string $name,
        private bool $forbids,
        private OrderedCondition $condition,
        private OrderedFact $fact,
        private OrderedConditions $conditions,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function matches(Request $request): bool
    {
        return true;
    }

    public function decide(Request $request): RuleAnswer
    {
        return $this->forbids
            ? $this->conditions->forbid($this->fact, $this->condition, \sprintf('Forbidden at readiness %s.', $this->condition))
            : $this->conditions->permit($this->fact, $this->condition, \sprintf('Permitted at readiness %s.', $this->condition));
    }
}
