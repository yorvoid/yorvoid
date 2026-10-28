<?php

declare(strict_types=1);

namespace YorvoID\Core;

use YorvoID\Core\Exception\IntegratorError;

/**
 * A length of time in whole seconds: how long a token, a session or a re-authentication window lasts.
 *
 * Configuration accepts it as a short string, `90s`, `15m`, `8h` or `30d`, so lifetimes read the same in
 * code, YAML and environment variables. Months and years aren't accepted: their length varies, and a
 * security lifetime must mean exactly one thing.
 *
 * @api
 */
final readonly class Duration implements \Stringable
{
    /** Longer than any lifetime YorvoID uses (100 years); catches milliseconds passed as seconds and the like */
    public const int MAX_SECONDS = 3_153_600_000;

    private const array UNITS = ['d' => 86_400, 'h' => 3_600, 'm' => 60, 's' => 1];

    private function __construct(public int $seconds)
    {
        if ($seconds < 1 || $seconds > self::MAX_SECONDS) {
            throw new IntegratorError(\sprintf('A duration of %d seconds is out of range.', $seconds), \sprintf('Use between 1 second and %d seconds (100 years).', self::MAX_SECONDS), 'concepts/configuration.md#durations');
        }
    }

    public static function seconds(int $seconds): self
    {
        return new self($seconds);
    }

    public static function minutes(int $minutes): self
    {
        return self::multiplied($minutes, 60);
    }

    public static function hours(int $hours): self
    {
        return self::multiplied($hours, 3_600);
    }

    public static function days(int $days): self
    {
        return self::multiplied($days, 86_400);
    }

    /**
     * Parses `90s`, `15m`, `8h` or `30d`.
     */
    public static function parse(string $value): self
    {
        if (1 !== preg_match('/^(?<amount>[1-9][0-9]{0,9})(?<unit>[smhd])$/D', $value, $match)) {
            throw new IntegratorError(\sprintf('"%s" is not a duration.', $value), 'Write a whole number followed by s, m, h or d, e.g. "90s", "15m", "8h" or "30d".', 'concepts/configuration.md#durations');
        }

        return self::multiplied((int) $match['amount'], self::UNITS[$match['unit']]);
    }

    /**
     * The moment this long after the given one: exactly this many seconds later, also across a change to
     * or from daylight saving time, in the given moment's timezone.
     */
    public function after(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        // In UTC, because adding seconds to a local time adds wall-clock time, which is an hour off
        // across a daylight saving change.
        return $moment->setTimezone(new \DateTimeZone('UTC'))->modify(\sprintf('+%d seconds', $this->seconds))->setTimezone($moment->getTimezone());
    }

    public function isLongerThan(self $other): bool
    {
        return $this->seconds > $other->seconds;
    }

    public function equals(self $other): bool
    {
        return $this->seconds === $other->seconds;
    }

    /**
     * The shortest exact form, e.g. `8h` for 28 800 seconds and `90m` for 5 400.
     */
    public function __toString(): string
    {
        foreach (['d' => 86_400, 'h' => 3_600, 'm' => 60] as $unit => $size) {
            if (0 === $this->seconds % $size) {
                return \sprintf('%d%s', intdiv($this->seconds, $size), $unit);
            }
        }

        return \sprintf('%ds', $this->seconds);
    }

    private static function multiplied(int $amount, int $size): self
    {
        if ($amount < 1 || $amount > intdiv(self::MAX_SECONDS, $size)) {
            throw new IntegratorError(\sprintf('A duration of %d × %d seconds is out of range.', $amount, $size), 'Use a positive amount of at most 100 years.', 'concepts/configuration.md#durations');
        }

        return new self($amount * $size);
    }
}
