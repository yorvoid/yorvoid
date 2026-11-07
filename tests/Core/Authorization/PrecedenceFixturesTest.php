<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Authorization\Fixtures\StubForbid;
use YorvoID\Tests\Core\Authorization\Fixtures\StubPermit;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;

/**
 * The evaluator gives the precedence model's outcome for every request the model enumerates: up to 6
 * matching elements, each permit or forbid holding, failing or unknown, with each authority
 * (spec/decisions/precedence.als, its "fixtures" run; check.sh keeps the file the model's).
 */
final class PrecedenceFixturesTest extends TestCase
{
    public function testTheEvaluatorDecidesAsTheModel(): void
    {
        $fixtures = json_decode((string) file_get_contents(__DIR__.'/../../../spec/decisions/fixtures.json'), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($fixtures));
        self::assertCount(2772, $fixtures, 'Every multiset of up to 6 of the 6 kinds of element, with each of 3 authorities.');
        $operation = new OperationDescription('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Ability], 'acme.badges.issue', null, IssueBadge::class, []);
        $command = new IssueBadge(new Realm('acme'), new PrincipalId(new UuidV7()));
        $actor = new Member(new Realm('acme'), new PrincipalId(new UuidV7()));

        foreach ($fixtures as $fixture) {
            \assert(\is_array($fixture) && \is_string($fixture[0]) && \is_string($fixture[1]) && \is_string($fixture[2]));
            [$codes, $authority, $outcome] = $fixture;
            $elements = [];

            foreach ('' === $codes ? [] : explode(',', $codes) as $j => $code) {
                $truth = self::truth($code[1]);
                $elements[] = 'P' === $code[0] ? new StubPermit('element-'.$j, $truth) : new StubForbid('element-'.$j, $truth);
            }

            $decision = new Evaluator($elements, new StubAuthority(self::truth(['holds' => '+', 'fails' => '-', 'unknown' => '?'][$authority])))->decide($operation, $command, $actor, new Origin(OriginKind::Web));

            self::assertSame(Verdict::from($outcome), $decision->verdict, \sprintf('Elements "%s", authority %s', $codes, $authority));
        }
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
