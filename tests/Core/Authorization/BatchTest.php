<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\Storage\InMemoryDecisionLogStore;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\VersionSource;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Tests\Core\Authorization\Fixtures\PreloadingPermit;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;

/**
 * A batch of ability decisions (AUZ-030, AUZ-032): each decided as on its own, at one moment, with the
 * versions read once, after the elements and the authority check that preload have read for it.
 */
final class BatchTest extends TestCase
{
    public function testEachResourceIsDecidedAtOneMomentInOrder(): void
    {
        $permit = new PreloadingPermit();
        $versions = new class implements VersionSource {
            public int $reads = 0;

            public function name(): string
            {
                return 'policy';
            }

            public function version(Realm $realm): int
            {
                return ++$this->reads;
            }
        };
        $store = new InMemoryDecisionLogStore();
        $log = new DecisionLog($store);
        $evaluator = new Evaluator([$permit], new StubAuthority(Truth::holds('valid')), new MockClock('2026-11-08 20:00:00'), versions: [$versions], decisions: $log);
        $pages = [new ResourceRef('page', '1'), new ResourceRef('page', 'other'), new ResourceRef('page', '1'), null];

        $decisions = $evaluator->decideAbilities(self::member(), 'acme.pages.edit', new Realm('acme'), array_map(static fn (?ResourceRef $page): array => [$page, null], $pages), new Origin(OriginKind::Web));

        self::assertSame([Verdict::Permit, Verdict::Deny, Verdict::Permit, Verdict::Permit], array_map(static fn (Decision $decision): Verdict => $decision->verdict, $decisions));
        self::assertSame('No permit applies to "acme.pages.edit".', $decisions[1]->reason, 'Not matched, so not preloaded either.');
        self::assertCount(1, $permit->batches);
        self::assertSame([$pages[0], $pages[2], null], array_map(static fn ($request) => $request->resource, $permit->batches[0]), 'Only the requests it matches.');
        self::assertSame(1, $versions->reads, 'The versions are read once for the batch.');
        self::assertSame([['policy' => 1]], array_values(array_unique(array_map(static fn (Decision $decision): array => $decision->versions, $decisions), \SORT_REGULAR)));
        self::assertSame(['2026-11-08 20:00:00'], array_values(array_unique(array_map(static fn (Decision $decision): string => ($decision->at ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'), $decisions))));
        self::assertCount(4, array_unique(array_map(static fn (Decision $decision): ?string => $decision->id, $decisions)), 'Each decision has its own ID.');
        $log->flush();
        self::assertNotNull($store->find(new Realm('acme'), (string) $decisions[1]->id), 'Each is recorded as on its own.');

        self::assertSame([], $evaluator->decideAbilities(self::member(), 'acme.pages.edit', new Realm('acme'), [], new Origin(OriginKind::Web)));
        self::assertSame(1, $versions->reads, 'An empty batch reads nothing.');
    }

    public function testTheAuthorityCheckPreloadsTheWholeBatch(): void
    {
        $authority = new PreloadingPermit();
        $evaluator = new Evaluator([new PreloadingPermit('permit')], $authority);

        $decisions = $evaluator->decideAbilities(self::member(), 'acme.pages.edit', new Realm('acme'), [[new ResourceRef('page', 'other'), null], [new ResourceRef('page', '1'), null]], new Origin(OriginKind::Web));

        self::assertCount(2, $authority->batches[0], 'Every request: authority isn\'t matched.');
        self::assertSame(Verdict::Permit, $decisions[1]->verdict);
        self::assertSame(Verdict::Permit, $evaluator->decideAbilities(self::member(), 'acme.pages.edit', new Realm('acme'), [[new ResourceRef('page', '1'), null]], new Origin(OriginKind::Web))[0]->verdict);
        self::assertSame(Verdict::Deny, $evaluator->decideAbility(self::member(), 'acme.pages.edit', new Realm('acme'), new ResourceRef('page', '1'), new Origin(OriginKind::Web))->verdict, 'Alone, nothing is preloaded.');
    }

    public function testWhatFailsToPreloadIsReadRequestByRequest(): void
    {
        $failing = new PreloadingPermit('failing', new \RuntimeException('The store is down.'));
        $matching = new PreloadingPermit('matching', matchFailure: new \RuntimeException('Can\'t tell.'));
        $other = new PreloadingPermit('other', basis: AuthorizedBy::Operator);
        $evaluator = new Evaluator([$failing, $matching, $other], new PreloadingPermit('authority', new \RuntimeException('Down too.')));

        $decisions = $evaluator->decideAbilities(self::member(), 'acme.pages.edit', new Realm('acme'), [[new ResourceRef('page', '1'), null]], new Origin(OriginKind::Web));

        self::assertSame(Verdict::Deny, $decisions[0]->verdict);
        self::assertSame('The actor\'s authority is invalid: Read alone.', $decisions[0]->reason, 'Decided as without preloading.');
        self::assertCount(1, $failing->batches);
        self::assertSame([], $matching->batches);
        self::assertSame([], $other->batches, 'A permit that doesn\'t speak for abilities isn\'t asked.');

        $integrator = new Evaluator([new PreloadingPermit('wrong', new IntegratorError('Set up wrong.', 'Fix it.', 'concepts/authorization.md'))], new StubAuthority(Truth::holds('valid')));

        try {
            $integrator->decideAbilities(self::member(), 'acme.pages.edit', new Realm('acme'), [[new ResourceRef('page', '1'), null]], new Origin(OriginKind::Web));
            self::fail('A mistake in the setup was decided around.');
        } catch (IntegratorError $error) {
            self::assertStringStartsWith('Set up wrong.', $error->getMessage());
        }

        $authority = new Evaluator([], new PreloadingPermit('wrong', new IntegratorError('Set up wrong.', 'Fix it.', 'concepts/authorization.md')));
        $this->expectException(IntegratorError::class);
        $authority->decideAbilities(self::member(), 'acme.pages.edit', new Realm('acme'), [[new ResourceRef('page', '1'), null]], new Origin(OriginKind::Web));
    }

    private static function member(): Member
    {
        return new Member(new Realm('acme'), new PrincipalId(new UuidV7()));
    }
}
