<?php

declare(strict_types=1);

namespace YorvoID\Tests\Throttle;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Throttle\Limit;
use YorvoID\Throttle\Storage\DbalThrottleStore;
use YorvoID\Throttle\Throttle;
use YorvoID\Throttle\Throttled;
use YorvoID\Throttle\ThrottleStore;

/**
 * Throttling on every implementation (ATN-040, ATN-041).
 */
final class ThrottleTest extends DatabaseTestCase
{
    private MockClock $clock;

    #[DataProvider('implementations')]
    public function testAllowsALimitsAttemptsAtOnceThenOnePerInterval(string $implementation): void
    {
        $throttle = $this->throttle($implementation);
        $limit = new Limit(3, Duration::seconds(3));

        for ($i = 0; $i < 3; ++$i) {
            $throttle->hit('sign_in:ana', $limit);
        }

        $this->refused(1, '2026-10-28 18:00:01', static fn () => $throttle->hit('sign_in:ana', $limit));
        $this->clock->sleep(0.999);
        $this->refused(1, '2026-10-28 18:00:01', static fn () => $throttle->hit('sign_in:ana', $limit), 'A refused attempt was counted, or the interval is short.');

        $this->clock->sleep(0.001);
        $throttle->hit('sign_in:ana', $limit);
        $this->refused(1, '2026-10-28 18:00:02', static fn () => $throttle->hit('sign_in:ana', $limit));

        // A key that waited the whole period has all its attempts again, and no more.
        $this->clock->sleep(3);

        for ($i = 0; $i < 3; ++$i) {
            $throttle->hit('sign_in:ana', $limit);
        }

        $this->refused(1, '2026-10-28 18:00:05', static fn () => $throttle->hit('sign_in:ana', $limit));
    }

    #[DataProvider('implementations')]
    public function testKeysCountApart(string $implementation): void
    {
        $throttle = $this->throttle($implementation);
        $limit = new Limit(1, Duration::minutes(1));
        $throttle->hit('sign_in:ana', $limit);

        $throttle->hit('sign_in:ben', $limit);
        $this->refused(60, '2026-10-28 18:01:00', static fn () => $throttle->hit('sign_in:ana', $limit));
    }

    #[DataProvider('implementations')]
    public function testCheckingCountsNothing(string $implementation): void
    {
        $throttle = $this->throttle($implementation);
        $limit = new Limit(2, Duration::minutes(1));

        for ($i = 0; $i < 5; ++$i) {
            $throttle->check('sign_in:ana', $limit);
        }

        $throttle->hit('sign_in:ana', $limit);
        $throttle->check('sign_in:ana', $limit);
        $throttle->hit('sign_in:ana', $limit);
        $this->refused(30, '2026-10-28 18:00:30', static fn () => $throttle->check('sign_in:ana', $limit));
    }

    #[DataProvider('implementations')]
    public function testResettingForgetsAKeysAttempts(string $implementation): void
    {
        $throttle = $this->throttle($implementation);
        $limit = new Limit(1, Duration::hours(1));
        $throttle->hit('sign_in:ana', $limit);
        $throttle->hit('sign_in:ben', $limit);

        $throttle->reset('sign_in:ana');

        $throttle->hit('sign_in:ana', $limit);
        $this->refused(3600, '2026-10-28 19:00:00', static fn () => $throttle->hit('sign_in:ben', $limit), 'Resetting one key reset another.');
    }

    #[DataProvider('implementations')]
    public function testPurgesKeysThatAreClear(string $implementation): void
    {
        $throttle = $this->throttle($implementation);
        $throttle->hit('a', new Limit(2, Duration::minutes(1)));
        $throttle->hit('b', new Limit(1, Duration::minutes(2)));
        self::assertSame(0, $throttle->purge());

        $this->clock->sleep(30);
        self::assertSame(1, $throttle->purge(), 'The key clear at this very moment stayed.');
        $this->clock->sleep(89.999);
        self::assertSame(0, $throttle->purge());
        $this->clock->sleep(0.001);
        self::assertSame(1, $throttle->purge());
    }

    public function testAnIntervalIsThePeriodSharedOutRoundedUp(): void
    {
        self::assertSame(334, new Limit(3, Duration::seconds(1))->interval());
        self::assertSame(90_000, new Limit(10, Duration::minutes(15))->interval());
        self::assertSame(1000, new Limit(1, Duration::seconds(1))->interval());
    }

    public function testALimitAllowsSomething(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('A limit of 0 attempts allows nothing.');

        new Limit(0, Duration::minutes(1));
    }

    #[DataProvider('platforms')]
    public function testStoresOnlyTheKeysHash(string $platform): void
    {
        $throttle = $this->throttle($platform);
        $throttle->hit('sign_in:ana@example.com', new Limit(5, Duration::minutes(1)));
        $database = $this->stack($platform)->database;
        \assert($database instanceof Database);

        self::assertSame([hash('sha256', 'sign_in:ana@example.com')], $database->connection->fetchFirstColumn(\sprintf('SELECT key_hash FROM %s', $database->table(DbalThrottleStore::TABLE))));
    }

    #[DataProvider('platforms')]
    public function testRefusesToCountInsideATransaction(string $platform): void
    {
        $stack = $this->stack($platform);
        $database = $stack->database;
        \assert($database instanceof Database);
        $throttle = new Throttle($stack->throttle);
        $database->connection->beginTransaction();

        foreach ([static fn () => $throttle->hit('a', new Limit(5, Duration::minutes(1))), static fn () => $throttle->reset('a')] as $change) {
            try {
                $change();
                self::fail('An attempt was counted where a rollback would undo it.');
            } catch (IntegratorError $exception) {
                self::assertStringStartsWith('Throttling inside a transaction', $exception->getMessage());
            }
        }

        $throttle->check('a', new Limit(5, Duration::minutes(1)));
        $database->connection->rollBack();
    }

    public function testAKeyOthersKeepChangingIsTreatedAsBusy(): void
    {
        $this->clock = new MockClock('2026-10-28 18:00:00 UTC');
        $losing = new class implements ThrottleStore {
            public int $tries = 0;

            public function clearAt(string $key): ?int
            {
                return null;
            }

            public function replace(string $key, ?int $expected, int $clearAt): bool
            {
                ++$this->tries;

                return false;
            }

            public function remove(string $key): void
            {
            }

            public function purge(int $now): int
            {
                return 0;
            }
        };

        $this->refused(6, '2026-10-28 18:00:06', static fn () => new Throttle($losing, new MockClock('2026-10-28 18:00:00 UTC'))->hit('a', new Limit(10, Duration::minutes(1))));
        self::assertSame(10, $losing->tries);
    }

    private function throttle(string $implementation): Throttle
    {
        $this->clock = new MockClock('2026-10-28 18:00:00 UTC');

        return new Throttle($this->stack($implementation)->throttle, $this->clock);
    }

    /**
     * @param \Closure(): mixed $attempt
     */
    private function refused(int $seconds, string $retryAfter, \Closure $attempt, string $message = ''): void
    {
        try {
            $attempt();
            self::fail('' === $message ? 'An attempt over the limit was allowed.' : $message);
        } catch (Throttled $exception) {
            self::assertSame($seconds, $exception->seconds, $message);
            self::assertEquals(new \DateTimeImmutable($retryAfter.' UTC'), $exception->retryAfter, $message);
            self::assertSame(\sprintf('Too many attempts. Please try again in %d seconds.', $seconds), $exception->getMessage());
        }
    }
}
