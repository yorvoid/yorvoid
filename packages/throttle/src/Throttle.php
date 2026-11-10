<?php

declare(strict_types=1);

namespace YorvoID\Throttle;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Core\Crypto\Digest;

/**
 * Throttling (ATN-040, ATN-041): at most so many attempts per period, per key, e.g. per account and
 * address. Every server shares the counts through the store, and each attempt is counted in one atomic
 * step, so concurrent requests can't slip past the limit.
 *
 * It's the generic cell rate algorithm (GCRA): a key keeps one moment, when it will be clear of the
 * attempts counted; each attempt moves it on by the limit's interval, and an attempt is refused while
 * that moment is further ahead than the period allows. A limit's attempts may come at once; after that,
 * one is allowed per interval: throttling slows attempts down, it doesn't lock anyone out for long.
 *
 * Keys are stored as their SHA-256 hashes: they hold addresses and account IDs.
 *
 * @api
 */
final readonly class Throttle
{
    /** How often to count an attempt again when its key cleared between refusing it and saying until when */
    private const int RETRIES = 3;

    public function __construct(
        private ThrottleStore $store,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * Counts an attempt for the key.
     *
     * @param string $key E.g. "sign_in:".$accountId.":".AddressKey::of($ip)
     *
     * @throws Throttled When the limit is reached; nothing is counted
     */
    public function hit(string $key, Limit $limit): void
    {
        $hash = self::hash($key);

        for ($i = 0; $i < self::RETRIES; ++$i) {
            $now = $this->now();

            // Counted, or refused, in one step: concurrent attempts can't refuse each other (PERF-05).
            if (null !== $this->store->advance($hash, $now, $limit->interval(), $now + $limit->per->seconds * 1000 - $limit->interval())) {
                return;
            }

            // Refused: say until when. If the key cleared in the meantime, count the attempt again.
            $this->refuseUntil(max($this->store->clearAt($hash) ?? $now, $now), $limit, $now);
        }

        // The key keeps refusing and clearing at this very moment: an attack, most likely.
        $this->refuseUntil($this->now() + $limit->per->seconds * 1000, $limit, $this->now());
    }

    /**
     * Whether an attempt would be allowed now, without counting one.
     *
     * @throws Throttled When it wouldn't
     */
    public function check(string $key, Limit $limit): void
    {
        $now = $this->now();
        $this->refuseUntil(max($this->store->clearAt(self::hash($key)) ?? $now, $now), $limit, $now);
    }

    /**
     * Forgets the key's attempts, e.g. after someone signed in.
     */
    public function reset(string $key): void
    {
        $this->store->remove(self::hash($key));
    }

    /**
     * Removes the state of keys that are clear; run it daily, e.g. from a scheduled job.
     *
     * @return int How many
     */
    public function purge(): int
    {
        return $this->store->purge($this->now());
    }

    /**
     * @throws Throttled When the key is too far ahead for one more attempt
     */
    private function refuseUntil(int $clearAt, Limit $limit, int $now): void
    {
        // One more attempt would move the key past now plus the period.
        $allowedAt = $clearAt + $limit->interval() - $limit->per->seconds * 1000;

        if ($allowedAt > $now) {
            throw new Throttled(new \DateTimeImmutable('@'.intdiv($allowedAt, 1000))->modify(\sprintf('+%d milliseconds', $allowedAt % 1000)), intdiv($allowedAt - $now + 999, 1000));
        }
    }

    private function now(): int
    {
        return (int) $this->clock->now()->format('Uv');
    }

    private static function hash(string $key): string
    {
        return Digest::sha256($key);
    }
}
