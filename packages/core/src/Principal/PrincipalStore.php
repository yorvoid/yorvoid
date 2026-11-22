<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Realm;

/**
 * Where principals are kept. Every read names its realm, so nothing crosses realms (D-14).
 *
 * @api
 *
 * @spi Implement it, don't call it: calling a store changes YorvoID's records without asking YorvoID
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
     * Locks the principal's row until the transaction ends, before the reads a change of theirs depends
     * on: changes of one principal, e.g. two removals of their last two passkeys, then happen one after
     * the other, and each reads what the other committed. It's a write, e.g. of a counter of its own:
     * under REPEATABLE READ, a transaction that waited for it then fails as a conflict, and runs again
     * on what was committed, rather than going on with an older snapshot. Locks after the realm's
     * (Transactions::lockRealm()) and version rows, before the security log's (D-52). Inside a
     * transaction only.
     */
    public function lock(Realm $realm, PrincipalId $id): void;

    /**
     * Writes the principal's new status, if it's still at the status version $before has: another
     * transaction may have changed it since it was read.
     *
     * @return bool Whether it was written
     */
    public function changeStatus(Principal $before, Principal $after): bool;
}
