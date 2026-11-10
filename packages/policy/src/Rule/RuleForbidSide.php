<?php

declare(strict_types=1);

namespace YorvoID\Policy\Rule;

use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\Ground;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;

/**
 * A rule's forbid side ("<name>:forbid"): holds when the rule refuses, unknown when it can't tell.
 *
 * @internal Made by RuleElements
 */
final readonly class RuleForbidSide implements ForbidElement
{
    /**
     * @param \Closure(Request): RuleAnswer $answer
     */
    public function __construct(
        private Rule $rule,
        private \Closure $answer,
    ) {
    }

    public function name(): string
    {
        return $this->rule->name().':forbid';
    }

    public function matches(Request $request): bool
    {
        return $this->rule->matches($request);
    }

    public function evaluate(Request $request): Truth
    {
        $answer = ($this->answer)($request);

        return match ($answer->outcome) {
            RuleOutcome::Forbid => Truth::holds($answer->detail, Ground::rule($this->rule->name()), ...$answer->grounds),
            RuleOutcome::Unknown => Truth::unknown($answer->reason ?? IndeterminateReason::EvaluationError, $answer->detail)->grounded(Ground::rule($this->rule->name()), ...$answer->grounds),
            RuleOutcome::Permit, RuleOutcome::Abstain => Truth::fails(\sprintf('The rule doesn\'t refuse it (%s).', $answer->outcome->value)),
        };
    }
}
