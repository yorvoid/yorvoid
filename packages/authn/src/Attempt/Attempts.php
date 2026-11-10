<?php

declare(strict_types=1);

namespace YorvoID\Authn\Attempt;

use YorvoID\Core\Crypto\Digest;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Throttle\AddressKey;
use YorvoID\Throttle\Limit;
use YorvoID\Throttle\Throttle;
use YorvoID\Throttle\Throttled;

/**
 * Counts attempts at the forms that check a secret (ATN-040): per account and address, per account, and
 * per address, as far as each is known. The services that check secrets count their attempts here,
 * before their transaction begins, so a rollback can't undo the count.
 *
 * @api
 */
final readonly class Attempts
{
    /**
     * @param SecurityLog|null $log Where a limit reached is recorded, once per key and window; not recorded without it
     */
    public function __construct(
        private Throttle $throttle,
        private AttemptLimits $limits = new AttemptLimits(),
        private ?SecurityLog $log = null,
    ) {
    }

    /**
     * Counts an attempt. Nothing is counted when neither the account nor the address is known.
     *
     * @param string                  $form        What is attempted, e.g. "recovery_code"
     * @param PrincipalId|string|null $account     The principal, or what identifies the account as typed (e.g.
     *                                             "email:ana@example.com", normalized), so attempts count the same
     *                                             whether or not the account exists
     * @param string|null             $address     The client's IP address
     * @param bool                    $knownDevice Whether the browser is one the account signed in with before (Sessions::isKnownDevice()):
     *                                             then the limit for the account isn't counted, which others can fill to keep
     *                                             the owner out; the ones for the account from the address, and for the address, are
     *
     * @throws Throttled When a limit is reached; then nothing is counted
     */
    public function count(string $form, Realm $realm, PrincipalId|string|null $account, ?string $address, bool $knownDevice = false): void
    {
        $account = null === $account ? null : self::account($form, $realm, $account);
        $address = null === $address ? null : self::address($form, $address);
        $keys = [];

        if (null !== $account && null !== $address) {
            $keys[$account.'@'.$address] = $this->limits->perAccountAndAddress;
        }

        if (null !== $account && !$knownDevice) {
            $keys[$account] = $this->limits->perAccount;
        }

        if (null !== $address) {
            $keys[$address] = $this->limits->perAddress;
        }

        // All or none: an attempt a later limit refuses doesn't use up the earlier ones.
        foreach ($keys as $key => $limit) {
            try {
                $this->throttle->check($key, $limit);
            } catch (Throttled $throttled) {
                $this->recordOnce($form, $realm, $key, $limit);

                throw $throttled;
            }
        }

        foreach ($keys as $key => $limit) {
            $this->throttle->hit($key, $limit);
        }
    }

    /**
     * Forgets an account's attempts from the address, after one succeeded: someone who mistyped a few
     * times isn't slowed down next time.
     */
    public function succeeded(string $form, Realm $realm, PrincipalId|string $account, ?string $address): void
    {
        if (null !== $address) {
            $this->throttle->reset(self::account($form, $realm, $account).'@'.self::address($form, $address));
        }
    }

    /**
     * Records that a limit refused an attempt, the first time in its window: a flood of refused attempts
     * writes one entry, not one each. The key is hashed: it holds what was typed, and the address.
     */
    private function recordOnce(string $form, Realm $realm, string $key, Limit $limit): void
    {
        $marker = \sprintf('recorded:%s', $key);
        $once = new Limit(1, $limit->per);

        try {
            $this->throttle->check($marker, $once);
        } catch (Throttled) {
            return;
        }

        $this->throttle->hit($marker, $once);
        $this->log?->record($realm, 'yorvoid.attempt.throttled', Outcome::Denied, new Origin(OriginKind::System, 'throttle'), details: ['form' => $form, 'key' => Digest::sha256($key)]);
    }

    private static function account(string $form, Realm $realm, PrincipalId|string $account): string
    {
        return \sprintf('%s:%s:%s', $form, $realm->name, $account);
    }

    private static function address(string $form, string $address): string
    {
        return \sprintf('%s:%s', $form, AddressKey::of($address));
    }
}
