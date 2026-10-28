<?php

declare(strict_types=1);

namespace YorvoID\Authn\Attempt;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Throttle\AddressKey;
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
    public function __construct(
        private Throttle $throttle,
        private AttemptLimits $limits = new AttemptLimits(),
    ) {
    }

    /**
     * Counts an attempt. Nothing is counted when neither the account nor the address is known.
     *
     * @param string      $form    What is attempted, e.g. "recovery_code"
     * @param string|null $address The client's IP address
     *
     * @throws Throttled When a limit is reached
     */
    public function count(string $form, Realm $realm, ?PrincipalId $principalId, ?string $address): void
    {
        $account = null === $principalId ? null : \sprintf('%s:%s:%s', $form, $realm->name, $principalId);
        $address = null === $address ? null : \sprintf('%s:%s', $form, AddressKey::of($address));

        if (null !== $account && null !== $address) {
            $this->throttle->hit($account.'@'.$address, $this->limits->perAccountAndAddress);
        }

        if (null !== $account) {
            $this->throttle->hit($account, $this->limits->perAccount);
        }

        if (null !== $address) {
            $this->throttle->hit($address, $this->limits->perAddress);
        }
    }

    /**
     * Forgets an account's attempts from the address, after one succeeded: someone who mistyped a few
     * times isn't slowed down next time.
     */
    public function succeeded(string $form, Realm $realm, PrincipalId $principalId, ?string $address): void
    {
        if (null !== $address) {
            $this->throttle->reset(\sprintf('%s:%s:%s@%s:%s', $form, $realm->name, $principalId, $form, AddressKey::of($address)));
        }
    }
}
