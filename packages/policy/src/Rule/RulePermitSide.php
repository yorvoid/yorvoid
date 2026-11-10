<?php

declare(strict_types=1);

namespace YorvoID\Policy\Rule;

use YorvoID\Core\Authorization\Ground;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\AuthorizedBy;

/**
 * A rule's permit side ("<name>:permit"): holds when the rule permits. It's never unknown: what the rule
 * can't tell, its forbid side says.
 *
 * @internal Made by RuleElements
 */
final readonly class RulePermitSide implements PermitElement
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
        return $this->rule->name().':permit';
    }

    public function basis(): AuthorizedBy
    {
        return AuthorizedBy::Ability;
    }

    public function matches(Request $request): bool
    {
        return $this->rule->matches($request);
    }

    public function evaluate(Request $request): Truth
    {
        $answer = ($this->answer)($request);

        return RuleOutcome::Permit === $answer->outcome ? Truth::holds($answer->detail, Ground::rule($this->rule->name()), ...$answer->grounds) : Truth::fails(\sprintf('The rule doesn\'t permit it (%s).', $answer->outcome->value));
    }
}
