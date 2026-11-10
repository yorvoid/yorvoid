<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\OrderedFact;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Policy\Ordered\OrderedCondition;
use YorvoID\Policy\Ordered\OrderedConditions;
use YorvoID\Policy\Rule\RuleOutcome;

/**
 * Conditions on an ordered fact (XP-15), on a bounded scale where higher is worse: unknown is missing
 * data by default (MP-1); pessimistic, decided at every value from the last known one up.
 */
final class OrderedConditionsTest extends TestCase
{
    public function testAKnownFactIsTheConditionAtItsValue(): void
    {
        foreach ([false, true] as $pessimistic) {
            $conditions = new OrderedConditions($pessimistic);

            foreach ([0, 1, 2, 3] as $level) {
                $fact = OrderedFact::known('readiness', 0, 3, $level);
                $holds = $level >= 2;

                self::assertSame($holds ? RuleOutcome::Forbid : RuleOutcome::Abstain, $conditions->forbid($fact, OrderedCondition::atLeast(2), 'no')->outcome);
                self::assertSame($holds ? RuleOutcome::Permit : RuleOutcome::Abstain, $conditions->permit($fact, OrderedCondition::atLeast(2), 'yes')->outcome);
            }
        }
    }

    public function testByDefaultAnUnknownFactIsMissingData(): void
    {
        $conditions = new OrderedConditions();

        foreach ([null, 0, 3] as $last) {
            foreach ([$conditions->forbid(...), $conditions->permit(...)] as $answer) {
                $result = $answer(OrderedFact::unknown('readiness', 0, 3, $last), OrderedCondition::atLeast(2), 'detail');
                self::assertSame([RuleOutcome::Unknown, IndeterminateReason::MissingData, '"readiness" isn\'t known.'], [$result->outcome, $result->reason, $result->detail]);
            }
        }
    }

    /**
     * @return iterable<string, array{OrderedCondition, ?int, RuleOutcome, RuleOutcome}>
     */
    public static function pessimistic(): iterable
    {
        // The condition, the last known value; what a forbid and a permit on it answer.
        yield 'forbid ≥ 2, last 0: some value up is ≥ 2' => [OrderedCondition::atLeast(2), 0, RuleOutcome::Forbid, RuleOutcome::Abstain];
        yield '≥ 2, last 3: met at every value up, still unmet (RDY-04)' => [OrderedCondition::atLeast(2), 3, RuleOutcome::Forbid, RuleOutcome::Abstain];
        yield '≥ min, last 2: met at every value of the scale' => [OrderedCondition::atLeast(0), 2, RuleOutcome::Forbid, RuleOutcome::Permit];
        yield '≥ 0, last none: met everywhere' => [OrderedCondition::atLeast(0), null, RuleOutcome::Forbid, RuleOutcome::Permit];
        yield '≤ 1, last 1' => [OrderedCondition::atMost(1), 1, RuleOutcome::Forbid, RuleOutcome::Abstain];
        yield '≤ 1, last 2: no value up is ≤ 1' => [OrderedCondition::atMost(1), 2, RuleOutcome::Abstain, RuleOutcome::Abstain];
        yield '≤ max, last 2: met at the top of the scale' => [OrderedCondition::atMost(3), 2, RuleOutcome::Forbid, RuleOutcome::Permit];
        yield '≤ above max, last none' => [OrderedCondition::atMost(9), null, RuleOutcome::Forbid, RuleOutcome::Permit];
        yield '≥ max, last none: a Deny with no history (RDY-04)' => [OrderedCondition::atLeast(3), null, RuleOutcome::Forbid, RuleOutcome::Abstain];
        yield '≤ min, last none' => [OrderedCondition::atMost(0), null, RuleOutcome::Forbid, RuleOutcome::Abstain];
    }

    #[DataProvider('pessimistic')]
    public function testPessimisticallyAnUnknownFactIsDecidedAtItsWorst(OrderedCondition $condition, ?int $last, RuleOutcome $forbid, RuleOutcome $permit): void
    {
        $conditions = new OrderedConditions(pessimistic: true);
        $fact = OrderedFact::unknown('readiness', 0, 3, $last);

        self::assertSame($forbid, $conditions->forbid($fact, $condition, 'no')->outcome);
        self::assertSame($permit, $conditions->permit($fact, $condition, 'yes')->outcome);
    }

    /**
     * R2 by brute force on the scale: unknown, never more permissive than at any value it may have.
     */
    public function testUnknownIsNeverMorePermissiveThanAnyValueItMayHave(): void
    {
        $conditions = new OrderedConditions(pessimistic: true);

        foreach ([null, 0, 1, 2, 3] as $last) {
            foreach ([0, 1, 2, 3, 4] as $bound) {
                foreach ([OrderedCondition::atLeast($bound), OrderedCondition::atMost($bound)] as $condition) {
                    $unknown = OrderedFact::unknown('readiness', 0, 3, $last);

                    foreach (range($last ?? 0, 3) as $value) {
                        $known = OrderedFact::known('readiness', 0, 3, $value);
                        $case = \sprintf('%s, last %s, at %d', $condition, $last ?? 'none', $value);

                        if (RuleOutcome::Forbid === $conditions->forbid($known, $condition, 'no')->outcome) {
                            self::assertSame(RuleOutcome::Forbid, $conditions->forbid($unknown, $condition, 'no')->outcome, $case);
                        }

                        if (RuleOutcome::Abstain === $conditions->permit($known, $condition, 'yes')->outcome) {
                            self::assertSame(RuleOutcome::Abstain, $conditions->permit($unknown, $condition, 'yes')->outcome, $case);
                        }
                    }
                }
            }
        }
    }

    public function testAConditionSaysWhatItIs(): void
    {
        self::assertSame(['≥ 2', '≤ 0'], [(string) OrderedCondition::atLeast(2), (string) OrderedCondition::atMost(0)]);
    }

    public function testTheScaleIsDeclared(): void
    {
        self::assertSame([1, 3], OrderedFact::unknown('readiness', 0, 3, 1)->possible());
        self::assertSame([0, 3], OrderedFact::unknown('readiness', 0, 3)->possible());
        self::assertSame([2, 2], OrderedFact::known('readiness', 0, 3, 2)->possible());

        foreach ([[3, 3, null], [3, 0, null], [0, 3, 4], [0, 3, -1]] as [$min, $max, $value]) {
            try {
                null === $value ? OrderedFact::unknown('readiness', $min, $max) : OrderedFact::known('readiness', $min, $max, $value);
                self::fail(\sprintf('%d..%d, %s', $min, $max, $value ?? 'none'));
            } catch (IntegratorError) {
            }
        }

        $this->expectException(IntegratorError::class);
        OrderedFact::unknown('readiness', 0, 3, 5);
    }
}
