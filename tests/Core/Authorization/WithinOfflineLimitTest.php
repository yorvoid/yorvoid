<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Authorization\WithinOfflineLimit;
use YorvoID\Core\Clock\Instant;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StoreUnreachable;
use YorvoID\Testing\MockElapsed;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Authorization\Fixtures\StubOfflineDecider;
use YorvoID\Tests\Core\Authorization\Fixtures\StubPermit;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Core\Operation\Fixtures\Doors\OpenDoor;

/**
 * An offline limit (XP-03, XP-14): measured by elapsed time, so the wall clock can't stretch it, and only
 * ever refusing what the decider it wraps would allow (MP-4).
 */
final class WithinOfflineLimitTest extends TestCase
{
    public function testTheWallClockDoesntMoveTheLimit(): void
    {
        $clock = new MockClock('2026-11-09 23:00:00 UTC');
        $elapsed = new MockElapsed();
        $lost = $elapsed->mark();
        $evaluator = self::evaluator(new WithinOfflineLimit(new StubOfflineDecider(Truth::holds('The node\'s budget covers it.')), $elapsed, static fn (): Instant => $lost, Duration::hours(4)), $clock);

        self::assertSame(Verdict::PermitOffline, self::decide($evaluator)->verdict);

        $clock->modify('-1 day');
        $elapsed->advance(Duration::hours(3));
        self::assertSame(Verdict::PermitOffline, self::decide($evaluator)->verdict, 'The wall clock set back a day: three hours have passed.');

        $clock->modify('+1 week');
        $elapsed->advance(Duration::minutes(59));
        self::assertSame(Verdict::PermitOffline, self::decide($evaluator)->verdict, 'Forward a week: still under four hours.');

        $clock->modify('-1 month');
        $elapsed->advance(Duration::minutes(1));
        $decision = self::decide($evaluator);
        self::assertSame(Verdict::Indeterminate, $decision->verdict, 'Four hours, whatever the wall clock says: no more.');
        self::assertSame([IndeterminateReason::StoreUnreachable], $decision->indeterminate);

        $elapsed->reboot();
        self::assertSame(Verdict::Indeterminate, self::decide($evaluator)->verdict, 'A reboot doesn\'t start it again.');
    }

    public function testRefusesWhatItCantMeasure(): void
    {
        $elapsed = new MockElapsed();
        $request = self::request();
        $limit = static fn (?Instant $mark): WithinOfflineLimit => new WithinOfflineLimit(new StubOfflineDecider(Truth::holds('yes')), $elapsed, static fn (): ?Instant => $mark, Duration::hours(1));

        foreach ([
            'no mark' => null,
            'another boot\'s' => new Instant('boot-0', 0),
            'one ahead of now' => new Instant('boot-1', 5_000_000_000),
        ] as $case => $mark) {
            $truth = $limit($mark)->decide($request);
            self::assertSame(TruthValue::Fails, $truth->value, $case);
            self::assertSame('The offline limit can\'t be measured: no mark of this boot to measure it from.', $truth->detail);
        }

        $elapsed->advance(Duration::hours(1));
        self::assertSame(TruthValue::Holds, $limit(new Instant('boot-1', 1))->decide($request)->value, 'A nanosecond under.');
        $truth = $limit(new Instant('boot-1', 0))->decide($request);
        self::assertSame(TruthValue::Fails, $truth->value, 'At the limit, it has passed.');
        self::assertSame('The offline limit of 1h has passed.', $truth->detail);
    }

    /**
     * MP-4: it never turns what the decider it wraps doesn't allow into an allowance, whatever the time.
     */
    public function testNeverAllowsWhatItWrapsDoesnt(): void
    {
        $request = self::request();

        foreach ([Truth::fails('no'), Truth::unknown(IndeterminateReason::MissingData, 'unknown'), new \RuntimeException('down')] as $answer) {
            foreach ([1, 30, 60, 90] as $minutes) {
                foreach ([true, false] as $marked) {
                    $elapsed = new MockElapsed();
                    $mark = $elapsed->mark();
                    $elapsed->advance(Duration::minutes($minutes));
                    $inner = new StubOfflineDecider($answer);
                    $limit = new WithinOfflineLimit($inner, $elapsed, static fn (): ?Instant => $marked ? $mark : null, Duration::hours(1));

                    try {
                        $truth = $limit->decide($request);
                    } catch (\RuntimeException) {
                        self::assertInstanceOf(\RuntimeException::class, $answer, 'Only the decider\'s own failure.');

                        continue;
                    }

                    self::assertNotSame(TruthValue::Holds, $truth->value);
                    self::assertSame($answer instanceof Truth ? $answer->value : null, $truth->value, 'Its answer, as it gave it.');
                }
            }
        }
    }

    private static function evaluator(WithinOfflineLimit $offline, MockClock $clock): Evaluator
    {
        return new Evaluator([new StubPermit('door', new StoreUnreachable('The link home is down.'))], new StubAuthority(Truth::holds('valid')), $clock, offline: $offline);
    }

    private static function decide(Evaluator $evaluator): \YorvoID\Core\Operation\Decision
    {
        return $evaluator->decide(self::operation(), new OpenDoor(new Realm('acme')), new Member(new Realm('acme'), new PrincipalId(new UuidV7())), new Origin(OriginKind::Web));
    }

    private static function operation(): OperationDescription
    {
        return new OperationDescription('acme.door.open', 'Open a door', [AuthorizedBy::Ability], 'acme.doors.open', null, OpenDoor::class, [], [], true);
    }

    private static function request(): Request
    {
        return Request::forOperation(self::operation(), new OpenDoor(new Realm('acme')), new Member(new Realm('acme'), new PrincipalId(new UuidV7())), new Origin(OriginKind::Web), new \DateTimeImmutable());
    }
}
