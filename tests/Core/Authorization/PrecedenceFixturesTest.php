<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\Attributes\DataProvider;
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
use YorvoID\Tests\Core\Authorization\Fixtures\StubOfflineDecider;
use YorvoID\Tests\Core\Authorization\Fixtures\StubPermit;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;

/**
 * The evaluator gives the precedence model's outcome for every request the model enumerates
 * (spec/decisions/precedence.als; check.sh keeps the files the model's), in both configurations (XP-16):
 *
 * - **default** (enterprise): every extension point at its default, no OfflineDecider; up to 6 matching
 *   elements, each permit or forbid holding, failing or unknown, with each authority. Its outcomes are
 *   the committed fixtures.json's, so the default is checked unchanged (MP-1);
 * - **extended**: every M2 extension point engaged, an OfflineDecider and an operation that accepts
 *   Permit-offline (XP-03); up to 4 elements, which unknowns an unreachable store caused, and what the
 *   decider says. XP-15 joins it with M2-11.
 */
final class PrecedenceFixturesTest extends TestCase
{
    /** Each configuration: its fixtures, how many, and the extension points engaged in it */
    private const array CONFIGURATIONS = [
        'default' => ['fixtures.json', 2772, []],
        'extended' => ['fixtures-extended.json', 3960, ['offline']],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function configurations(): iterable
    {
        foreach (array_keys(self::CONFIGURATIONS) as $configuration) {
            yield $configuration => [$configuration];
        }
    }

    #[DataProvider('configurations')]
    public function testTheEvaluatorDecidesAsTheModel(string $configuration): void
    {
        [$file, $count, $engaged] = self::CONFIGURATIONS[$configuration];
        $fixtures = json_decode((string) file_get_contents(__DIR__.'/../../../spec/decisions/'.$file), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($fixtures));
        self::assertCount($count, $fixtures, 'Every multiset of elements the model enumerates, with each authority.');
        $offline = \in_array('offline', $engaged, true);
        $operation = new OperationDescription('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Ability], 'acme.badges.issue', null, IssueBadge::class, [], acceptsOffline: $offline);
        $command = new IssueBadge(new Realm('acme'), new PrincipalId(new UuidV7()));
        $actor = new Member(new Realm('acme'), new PrincipalId(new UuidV7()));
        $offlines = 0;

        foreach ($fixtures as $fixture) {
            \assert(\is_array($fixture) && \is_string($fixture[0]) && \is_string($fixture[1]));
            [$codes, $authority] = $fixture;
            $decider = $offline && \is_string($fixture[2]) ? $fixture[2] : null;
            $outcome = $fixture[$offline ? 3 : 2];
            \assert(\is_string($outcome));
            $elements = [];

            foreach ('' === $codes ? [] : explode(',', $codes) as $j => $code) {
                $truth = self::truth($code[1]);
                $elements[] = 'P' === $code[0] ? new StubPermit('element-'.$j, $truth) : new StubForbid('element-'.$j, $truth);
            }

            $evaluator = new Evaluator($elements, new StubAuthority(self::truth(['holds' => '+', 'fails' => '-', 'unknown' => '?', 'unreachable' => '~'][$authority])), offline: null === $decider ? null : new StubOfflineDecider(self::truth('holds' === $decider ? '+' : '-')));
            $decision = $evaluator->decide($operation, $command, $actor, new Origin(OriginKind::Web));

            self::assertSame(Verdict::from($outcome), $decision->verdict, \sprintf('%s: elements "%s", authority %s, decider %s', $configuration, $codes, $authority, $decider ?? 'none'));
            $offlines += 'permit_offline' === $outcome ? 1 : 0;
        }

        self::assertSame($offline, $offlines > 0, 'Permit-offline comes in the extended configuration only, and does come there.');
    }

    private static function truth(string $value): Truth
    {
        return match ($value) {
            '+' => Truth::holds('holds'),
            '-' => Truth::fails('fails'),
            '?' => Truth::unknown(IndeterminateReason::MissingData, 'unknown'),
            '~' => Truth::unknown(IndeterminateReason::StoreUnreachable, 'unreachable'),
            default => throw new \LogicException('The fixtures name '.$value),
        };
    }
}
