<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\Attempt;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Attempt\AttemptLimits;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Core\Duration;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Throttle\Limit;
use YorvoID\Throttle\Storage\InMemoryThrottleStore;
use YorvoID\Throttle\Throttle;
use YorvoID\Throttle\Throttled;

/**
 * Attempts at the forms that check a secret, per account and address, per account, and per address
 * (ATN-040, ATN-041).
 */
final class AttemptsTest extends TestCase
{
    private Attempts $attempts;

    public function testEachKnownKeyHasItsLimit(): void
    {
        $this->attempts(2, 3, 2);
        $ana = self::principal();
        $ben = self::principal();

        $this->attempts->count('recovery_code', new Realm('acme'), $ana, '192.0.2.1');
        $this->attempts->count('recovery_code', new Realm('acme'), $ana, '192.0.2.1');
        $this->throttled(fn () => $this->attempts->count('recovery_code', new Realm('acme'), $ana, '192.0.2.1'), 'One account from one address went past its limit.');

        $this->attempts->count('recovery_code', new Realm('acme'), $ana, '192.0.2.2');
        $this->throttled(fn () => $this->attempts->count('recovery_code', new Realm('acme'), $ana, '192.0.2.3'), 'One account from many addresses went past its limit.');

        $this->attempts->count('recovery_code', new Realm('acme'), $ben, '192.0.2.2');
        $this->throttled(fn () => $this->attempts->count('recovery_code', new Realm('acme'), $ben, '192.0.2.2'), 'One address over many accounts went past its limit.');

        // Forms, realms and addresses count apart.
        $this->attempts->count('setup_code', new Realm('acme'), $ana, '192.0.2.1');
        $this->attempts->count('recovery_code', new Realm('globex'), $ana, '192.0.2.9');
        $this->attempts->count('passkey_sign_in', new Realm('acme'), null, '192.0.2.2');
    }

    public function testCountsWhatIsKnown(): void
    {
        $this->attempts(1, 2, 1);

        for ($i = 0; $i < 5; ++$i) {
            $this->attempts->count('setup_code', new Realm('acme'), null, null);
        }

        $ana = self::principal();
        $this->attempts->count('recovery_code', new Realm('acme'), $ana, null);
        $this->attempts->count('recovery_code', new Realm('acme'), $ana, null);
        $this->throttled(fn () => $this->attempts->count('recovery_code', new Realm('acme'), $ana, null), 'An account without an address went past its limit.');

        $this->attempts->count('passkey_sign_in', new Realm('acme'), null, '2001:db8::1');
        $this->throttled(fn () => $this->attempts->count('passkey_sign_in', new Realm('acme'), null, '2001:db8::2'), 'Another address of the same /64 counted apart.');
    }

    public function testASuccessForgetsTheAccountsAttemptsFromTheAddressOnly(): void
    {
        $this->attempts(2, 3, 3);
        $ana = self::principal();
        $this->attempts->count('recovery_code', new Realm('acme'), $ana, '192.0.2.1');
        $this->attempts->count('recovery_code', new Realm('acme'), $ana, '192.0.2.1');

        $this->attempts->succeeded('recovery_code', new Realm('acme'), $ana, '192.0.2.1');
        $this->attempts->succeeded('recovery_code', new Realm('acme'), $ana, null);

        $this->attempts->count('recovery_code', new Realm('acme'), $ana, '192.0.2.1');
        $this->throttled(fn () => $this->attempts->count('recovery_code', new Realm('acme'), $ana, '192.0.2.1'), 'A success forgot the account\'s attempts from everywhere.');
    }

    public function testTheDefaultsSlowDownWithoutLockingOut(): void
    {
        $limits = new AttemptLimits();

        self::assertEquals([new Limit(10, Duration::minutes(15)), new Limit(100, Duration::hours(1)), new Limit(300, Duration::minutes(15))], [$limits->perAccountAndAddress, $limits->perAccount, $limits->perAddress]);
    }

    private function attempts(int $perAccountAndAddress, int $perAccount, int $perAddress): void
    {
        $this->attempts = new Attempts(new Throttle(new InMemoryThrottleStore(), new MockClock('2026-10-28 18:00:00 UTC')), new AttemptLimits(new Limit($perAccountAndAddress, Duration::hours(1)), new Limit($perAccount, Duration::hours(1)), new Limit($perAddress, Duration::hours(1))));
    }

    private static function principal(): PrincipalId
    {
        return new PrincipalId(new UuidV7());
    }

    /**
     * @param \Closure(): mixed $attempt
     */
    private function throttled(\Closure $attempt, string $message): void
    {
        try {
            $attempt();
            self::fail($message);
        } catch (Throttled) {
            $this->addToAssertionCount(1);
        }
    }
}
