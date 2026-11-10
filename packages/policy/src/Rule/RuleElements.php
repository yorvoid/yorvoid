<?php

declare(strict_types=1);

namespace YorvoID\Policy\Rule;

use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\PolicyElement;
use YorvoID\Core\Authorization\Request;

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

        $permit = new RulePermitSide($rule, $answer);
        $forbid = new RuleForbidSide($rule, $answer);

        return [$permit, $forbid];
    }

    /**
     * Whether the element is a rule's permit side, which is never unknown: e.g. for mutation testing,
     * which has nothing to make it fail where it couldn't tell.
     *
     * @internal Used by yorvoid/policy-testing; it changes only in a minor release
     */
    public static function isPermitSide(PolicyElement $element): bool
    {
        return $element instanceof RulePermitSide;
    }
}
