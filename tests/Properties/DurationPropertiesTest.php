<?php

declare(strict_types=1);

namespace YorvoID\Tests\Properties;

use Innmind\BlackBox\PHPUnit\BlackBox;
use Innmind\BlackBox\Set;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;

final class DurationPropertiesTest extends TestCase
{
    use BlackBox;

    private const array UNITS = ['s' => 1, 'm' => 60, 'h' => 3_600, 'd' => 86_400];

    public function testPrintingAndParsingRoundTrip(): void
    {
        self::forAll(Set::integers()->between(1, Duration::MAX_SECONDS))->then(static function (int $seconds): void {
            $duration = Duration::seconds($seconds);

            self::assertSame($seconds, Duration::parse((string) $duration)->seconds);
        });
    }

    public function testThePrintedFormIsTheShortest(): void
    {
        self::forAll(Set::integers()->between(1, Duration::MAX_SECONDS))->then(static function (int $seconds): void {
            $text = (string) Duration::seconds($seconds);
            $unit = $text[-1];
            $next = ['s' => 60, 'm' => 3_600, 'h' => 86_400, 'd' => null][$unit];

            self::assertSame($seconds, (int) substr($text, 0, -1) * self::UNITS[$unit]);
            self::assertTrue(null === $next || 0 !== $seconds % $next, \sprintf('%s could be written with a larger unit.', $text));
        });
    }

    public function testAddingIsExactInEveryTimeZone(): void
    {
        self::forAll(
            Set::integers()->between(0, 4_102_444_800),
            Set::integers()->between(1, 400 * 86_400),
            Set::of('UTC', 'Europe/Skopje', 'America/New_York', 'Australia/Lord_Howe', 'Asia/Kathmandu', 'Pacific/Chatham'),
        )->then(static function (int $timestamp, int $seconds, string $zone): void {
            $start = new \DateTimeImmutable('@'.$timestamp)->setTimezone(new \DateTimeZone($zone));
            $end = Duration::seconds($seconds)->after($start);

            self::assertSame($timestamp + $seconds, $end->getTimestamp());
            self::assertSame($zone, $end->getTimezone()->getName());
        });
    }

    public function testParsesExactlyWhatTheOracleAccepts(): void
    {
        self::forAll(Set::either(
            Set::strings()->madeOf(Set::of(...str_split('0123456789smhdSMHDyw.-+ ')))->between(0, 14),
            Set::strings()->unicode()->between(0, 14),
            // The edges: ten and eleven digits, the largest accepted amounts, and just beyond.
            Set::integers()->between(1_000_000_000, 99_999_999_999)->map(static fn (int $n): string => $n.'s'),
            Set::integers()->between(36_490, 36_510)->map(static fn (int $n): string => $n.'d'),
            Set::integers()->between(876_000 - 5, 876_000 + 5)->map(static fn (int $n): string => $n.'h'),
        ))->then(static function (string $value): void {
            try {
                $seconds = Duration::parse($value)->seconds;
            } catch (IntegratorError) {
                $seconds = null;
            }

            self::assertSame(self::oracle($value), $seconds, var_export($value, true));
        });
    }

    /**
     * Digits then one unit; no leading zero; at most ten digits; within the range.
     */
    private static function oracle(string $value): ?int
    {
        $length = \strlen($value);

        if ($length < 2 || $length > 11 || !isset(self::UNITS[$value[$length - 1]]) || '0' === $value[0]) {
            return null;
        }

        for ($i = 0; $i < $length - 1; ++$i) {
            if ($value[$i] < '0' || $value[$i] > '9') {
                return null;
            }
        }

        $seconds = (int) substr($value, 0, -1) * self::UNITS[$value[$length - 1]];

        return $seconds <= Duration::MAX_SECONDS ? $seconds : null;
    }
}
