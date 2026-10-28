<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * The devices each principal has signed in from, by the SHA-256 hash of their device cookie (SES-012).
 *
 * @api
 */
interface KnownDeviceStore
{
    /**
     * Records that the principal signed in from the device at the moment.
     *
     * @return bool Whether it had before
     */
    public function recordUse(Realm $realm, PrincipalId $principalId, string $cookieHash, \DateTimeImmutable $at): bool;

    /**
     * Whether the principal has signed in from any device before.
     */
    public function hasAny(Realm $realm, PrincipalId $principalId): bool;

    /**
     * Forgets the devices not used since the moment, in every realm.
     *
     * @return int How many
     */
    public function purge(\DateTimeImmutable $unusedSince): int;
}
