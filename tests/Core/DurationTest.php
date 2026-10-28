<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;

final class DurationTest extends TestCase
{
    public function testFactoriesAreExact(): void
    {
        self::assertSame(1, Duration::seconds(1)->seconds);
        self::assertSame(900, Duration::minutes(15)->seconds);
        self::assertSame(28_800, Duration::hours(8)->seconds);
        self::assertSame(2_592_000, Duration::days(30)->seconds);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function parsable(): iterable
    {
        yield 'seconds' => ['90s', 90];
        yield 'minutes' => ['15m', 900];
        yield 'hours' => ['8h', 28_800];
        yield 'days' => ['30d', 2_592_000];
        yield 'one second' => ['1s', 1];
        yield 'the maximum' => ['36500d', Duration::MAX_SECONDS];
    }

    #[DataProvider('parsable')]
    public function testParses(string $value, int $seconds): void
    {
        self::assertSame($seconds, Duration::parse($value)->seconds);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unparsable(): iterable
    {
        yield 'empty' => [''];
        yield 'no unit' => ['90'];
        yield 'zero' => ['0s'];
        yield 'leading zero' => ['015m'];
        yield 'negative' => ['-5m'];
        yield 'fraction' => ['1.5h'];
        yield 'months' => ['1M'];
        yield 'years' => ['1y'];
        yield 'weeks' => ['2w'];
        yield 'uppercase unit' => ['8H'];
        yield 'space' => ['8 h'];
        yield 'two units' => ['1h30m'];
        yield 'ISO 8601' => ['PT8H'];
        yield 'trailing newline' => ["8h\n"];
        yield 'beyond the maximum' => ['36501d'];
        yield 'eleven digits' => ['12345678901s'];
    }

    #[DataProvider('unparsable')]
    public function testRefusesWhatIsntADuration(string $value): void
    {
        $this->expectException(IntegratorError::class);

        Duration::parse($value);
    }

    /**
     * @return iterable<string, array{\Closure(): Duration}>
     */
    public static function outOfRange(): iterable
    {
        yield 'zero seconds' => [static fn (): Duration => Duration::seconds(0)];
        yield 'negative seconds' => [static fn (): Duration => Duration::seconds(-1)];
        yield 'one second too many' => [static fn (): Duration => Duration::seconds(Duration::MAX_SECONDS + 1)];
        yield 'zero minutes' => [static fn (): Duration => Duration::minutes(0)];
        yield 'too many hours' => [static fn (): Duration => Duration::hours(876_001)];
        yield 'too many days' => [static fn (): Duration => Duration::days(36_501)];
        yield 'overflowing days' => [static fn (): Duration => Duration::days(\PHP_INT_MAX)];
    }

    /**
     * @param \Closure(): Duration $create
     */
    #[DataProvider('outOfRange')]
    public function testRefusesOutOfRange(\Closure $create): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('out of range');

        $create();
    }

    public function testTheLimitsThemselvesAreAccepted(): void
    {
        self::assertSame(Duration::MAX_SECONDS, Duration::days(36_500)->seconds);
        self::assertSame(Duration::MAX_SECONDS, Duration::seconds(Duration::MAX_SECONDS)->seconds);
        self::assertSame(876_000 * 3_600, Duration::hours(876_000)->seconds);
        self::assertSame(52_560_000 * 60, Duration::minutes(52_560_000)->seconds);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function canonical(): iterable
    {
        yield 'days' => [2_592_000, '30d'];
        yield 'hours' => [28_800, '8h'];
        yield 'minutes not hours' => [5_400, '90m'];
        yield 'seconds' => [90, '90s'];
        yield 'one second' => [1, '1s'];
        yield 'hours not days' => [90_000, '25h'];
    }

    #[DataProvider('canonical')]
    public function testPrintsItsShortestExactForm(int $seconds, string $text): void
    {
        self::assertSame($text, (string) Duration::seconds($seconds));
        self::assertTrue(Duration::parse($text)->equals(Duration::seconds($seconds)));
    }

    public function testComparesAndAdds(): void
    {
        self::assertTrue(Duration::hours(8)->isLongerThan(Duration::hours(1)));
        self::assertFalse(Duration::hours(1)->isLongerThan(Duration::hours(8)));
        self::assertFalse(Duration::hours(1)->isLongerThan(Duration::minutes(60)));
        self::assertTrue(Duration::hours(1)->equals(Duration::minutes(60)));
        self::assertFalse(Duration::hours(1)->equals(Duration::minutes(61)));

        $start = new \DateTimeImmutable('2026-10-27 18:00:00 UTC');
        self::assertEquals(new \DateTimeImmutable('2026-10-28 02:00:00 UTC'), Duration::hours(8)->after($start));
    }

    public function testAddingIsExactAcrossDaylightSavingTime(): void
    {
        // Europe/Skopje leaves summer time on 25 October 2026: that day has 25 hours.
        $start = new \DateTimeImmutable('2026-10-25 00:00:00', new \DateTimeZone('Europe/Skopje'));

        $end = Duration::days(1)->after($start);

        self::assertSame(86_400, $end->getTimestamp() - $start->getTimestamp());
        self::assertSame('Europe/Skopje', $end->getTimezone()->getName());
        self::assertSame('2026-10-25 23:00:00', $end->format('Y-m-d H:i:s'));
    }

    public function testAddingKeepsMicroseconds(): void
    {
        $start = new \DateTimeImmutable('2026-10-27 18:00:00.123456 UTC');

        self::assertSame('18:01:30.123456', Duration::seconds(90)->after($start)->format('H:i:s.u'));
    }
}
