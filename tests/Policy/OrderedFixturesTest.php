<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\OrderedFact;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\Ordered\OrderedCondition;
use YorvoID\Policy\Ordered\OrderedConditions;
use YorvoID\Policy\Rule\RuleElements;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Authorization\Fixtures\StubForbid;
use YorvoID\Tests\Core\Authorization\Fixtures\StubPermit;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Policy\Fixtures\OrderedRule;

/**
 * Rules on an ordered fact (XP-15) decide as the precedence model's ordered conditions
 * (spec/decisions/precedence.als; check.sh keeps the file the model's): every request of up to 2
 * matching elements, plain or rules on a readiness level from 0 to 3, with each authority and each state
 * of the level, in both configurations: by default an unknown level is missing data (MP-1); in the
 * extended one, PessimisticOrdering engaged, it's decided at its worst (R2).
 */
final class OrderedFixturesTest extends TestCase
{
    public function testRulesOnAnOrderedFactDecideAsTheModel(): void
    {
        $fixtures = json_decode((string) file_get_contents(__DIR__.'/../../spec/decisions/fixtures-ordered.json'), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($fixtures));
        self::assertCount(7452, $fixtures, 'Every multiset of up to 2 elements the model enumerates, with each authority and state of the fact.');
        $operation = new OperationDescription('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Ability], 'acme.badges.issue', null, IssueBadge::class, []);
        $command = new IssueBadge(new Realm('acme'), new PrincipalId(new UuidV7()));
        $actor = new Member(new Realm('acme'), new PrincipalId(new UuidV7()));
        $missing = 0;

        foreach ($fixtures as $fixture) {
            \assert(\is_array($fixture) && \is_string($fixture[0]) && \is_string($fixture[1]) && \is_string($fixture[2]));
            [$codes, $authority, $state] = $fixture;
            $fact = '?' === $state[0]
                ? OrderedFact::unknown('readiness', 0, 3, '?' === $state ? null : (int) substr($state, 1))
                : OrderedFact::known('readiness', 0, 3, (int) $state);

            foreach (['default' => 3, 'extended' => 4] as $configuration => $column) {
                $outcome = $fixture[$column];
                \assert(\is_string($outcome));
                $conditions = new OrderedConditions(pessimistic: 'extended' === $configuration);
                $elements = [];

                foreach ('' === $codes ? [] : explode(',', $codes) as $j => $code) {
                    if (1 === preg_match('/^([PF])([<>]=)(\d)$/', $code, $parts)) {
                        $bound = (int) $parts[3];
                        $condition = '>=' === $parts[2] ? OrderedCondition::atLeast($bound) : OrderedCondition::atMost($bound);
                        array_push($elements, ...RuleElements::of(new OrderedRule('rule-'.$j, 'F' === $parts[1], $condition, $fact, $conditions)));
                        continue;
                    }

                    $truth = self::truth($code[1]);
                    $elements[] = 'P' === $code[0] ? new StubPermit('element-'.$j, $truth) : new StubForbid('element-'.$j, $truth);
                }

                $decision = new Evaluator($elements, new StubAuthority(self::truth(['holds' => '+', 'fails' => '-', 'unknown' => '?'][$authority])))->decide($operation, $command, $actor, new Origin(OriginKind::Web));
                self::assertSame(Verdict::from($outcome), $decision->verdict, \sprintf('%s: elements "%s", authority %s, readiness %s', $configuration, $codes, $authority, $state));

                // MP-1: by default, a rule on the unknown fact is missing data.
                if ('default' === $configuration && '?' === $state[0] && str_contains($codes, '=') && Verdict::Indeterminate === $decision->verdict) {
                    self::assertContains(IndeterminateReason::MissingData, $decision->indeterminate, $codes);
                    ++$missing;
                }
            }
        }

        self::assertGreaterThan(1000, $missing);
    }

    private static function truth(string $value): Truth
    {
        return match ($value) {
            '+' => Truth::holds('holds'),
            '-' => Truth::fails('fails'),
            '?' => Truth::unknown(IndeterminateReason::MissingData, 'unknown'),
            default => throw new \LogicException('The fixtures name '.$value),
        };
    }
}
