<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Realm;

/**
 * Where principals are kept. Every read names its realm, so nothing crosses realms (D-14).
 *
 * @api
 */
interface PrincipalStore
{
    /**
     * Adds a new principal; one with the same ID must not exist in any realm.
     */
    public function add(Principal $principal): void;

    public function get(Realm $realm, PrincipalId $id): ?Principal;

    /**
     * Whether the realm has an active principal, e.g. so a setup code can't create a second first one.
     */
    public function hasActive(Realm $realm): bool;

    /**
     * Writes the principal's new status, if it's still at the status version $before has: another
     * transaction may have changed it since it was read.
     *
     * @return bool Whether it was written
     */
    public function changeStatus(Principal $before, Principal $after): bool;
}
