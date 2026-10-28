<?php

declare(strict_types=1);

namespace YorvoID\Core;

/**
 * Times as YorvoID stores them: in UTC, to the second. Every supported database keeps exactly that,
 * so what's read back equals what was written, and what's hashed (the security log) is what's stored.
 * Order within a second comes from sequence numbers and time-ordered IDs, not from the time.
 *
 * @api
 */
final class Time
{
    public static function stored(\DateTimeImmutable $time): \DateTimeImmutable
    {
        $utc = $time->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), (int) $utc->format('s'));
    }
}
