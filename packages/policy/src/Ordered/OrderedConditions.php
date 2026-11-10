<?php

declare(strict_types=1);

namespace YorvoID\Policy\Ordered;

use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\OrderedFact;
use YorvoID\Policy\Rule\RuleAnswer;

/**
 * What a rule answers on an ordered fact (XP-15): a forbid, or a permit's requirement, on a condition
 * such as "level ≥ 2". A known fact is the condition at its value. An unknown one is missing data: the
 * decision is Indeterminate, as for any unknown.
 *
 * Pessimistic, the extension point (off by default, MP-1), an unknown fact is decided at every value it
 * may have, from the last known one to the scale's highest (higher being worse): a forbid applies if it
 * applies at any of them. A requirement counts as unmet (RDY-04) unless it's met at every value of the
 * scale, e.g. "≤ its highest": assuming the worst of the fact is no reason to meet a requirement, even
 * one that a worse value would meet. With no last known value, every value is possible, so a forbid on
 * any of them gives a Deny, not Indeterminate, which is what the mode is for.
 *
 * ```php
 * return $this->conditions->forbid($this->readiness($request), OrderedCondition::atLeast(3), 'At readiness 3 or worse, nothing is deleted.');
 * ```
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an extension's ordered conditions use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class OrderedConditions
{
    /**
     * @param bool $pessimistic Whether an unknown fact is decided at its worst values (XP-15's extension point)
     */
    public function __construct(
        public bool $pessimistic = false,
    ) {
    }

    /**
     * A forbid when the condition holds, else nothing.
     */
    public function forbid(OrderedFact $fact, OrderedCondition $condition, string $detail): RuleAnswer
    {
        return match ($this->decide($fact, $condition, any: true)) {
            true => RuleAnswer::forbid($detail),
            false => RuleAnswer::abstain(),
            null => self::unknown($fact),
        };
    }

    /**
     * A permit when the condition, a requirement, is met, else nothing.
     */
    public function permit(OrderedFact $fact, OrderedCondition $condition, string $detail): RuleAnswer
    {
        return match ($this->decide($fact, $condition, any: false)) {
            true => RuleAnswer::permit($detail),
            false => RuleAnswer::abstain(),
            null => self::unknown($fact),
        };
    }

    /**
     * Whether the condition holds: at the value, or, pessimistic and unknown, at any of the values the
     * fact may have (a forbid) or at every value of the scale (a requirement). Null when it can't tell.
     */
    private function decide(OrderedFact $fact, OrderedCondition $condition, bool $any): ?bool
    {
        if (null === $fact->value && !$this->pessimistic) {
            return null;
        }

        [$low, $high] = $any || null !== $fact->value ? $fact->possible() : [$fact->min, $fact->max];
        // The two ends are enough only because every condition is monotone: "at least" and "at most" hold
        // on a range's every value, or any, where they hold at its ends. A condition that isn't, e.g.
        // "between" or "equals", would be decided wrongly here, which is why OrderedCondition is closed
        // to those two.
        $atLow = $condition->holdsAt($low);
        $atHigh = $condition->holdsAt($high);

        return $any ? $atLow || $atHigh : $atLow && $atHigh;
    }

    private static function unknown(OrderedFact $fact): RuleAnswer
    {
        return RuleAnswer::unknown(IndeterminateReason::MissingData, \sprintf('"%s" isn\'t known.', $fact->name));
    }
}
