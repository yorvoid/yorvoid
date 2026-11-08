<?php

declare(strict_types=1);

namespace YorvoID\Policy\Rule;

use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\Ground;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\AuthorizedBy;

/**
 * A rule as the evaluator's elements: a forbid ("<name>:forbid") that holds when it refuses and is
 * unknown when it can't tell, and a permit by ability ("<name>:permit") that holds when it permits. The
 * rule is asked once per decision.
 *
 * ```php
 * Evaluator::builtIn($authority, elements: [new RolePermit($roles), ...RuleElements::of(new LockedPages())]);
 * ```
 *
 * @api
 */
final readonly class RuleElements
{
    /**
     * @return array{PermitElement, ForbidElement}
     */
    public static function of(Rule $rule): array
    {
        /** @var \WeakMap<Request, RuleAnswer> $answers */
        $answers = new \WeakMap();
        $answer = static function (Request $request) use ($rule, $answers): RuleAnswer {
            return $answers[$request] ??= $rule->decide($request);
        };

        $permit = new readonly class($rule, $answer) implements PermitElement {
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
        };

        $forbid = new readonly class($rule, $answer) implements ForbidElement {
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
        };

        return [$permit, $forbid];
    }
}
