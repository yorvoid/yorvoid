<?php

declare(strict_types=1);

namespace YorvoID\Delegation;

use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Where delegations are kept. Every change increments the realm's delegation version in its own
 * transaction, so a decision that read it sees the change at its next use.
 *
 * @api
 *
 * @spi Implement it, don't call it: calling a store changes YorvoID's records without asking YorvoID
 *
 * @experimental
 */
interface DelegationStore
{
    /**
     * Adds a new delegation; one with the same ID must not exist in any realm.
     */
    public function add(Delegation $delegation): void;

    public function get(Realm $realm, DelegationId $id): ?Delegation;

    /**
     * The active delegations issued by or to the principal, in the order they were issued.
     *
     * @return list<Delegation>
     */
    public function involving(Realm $realm, PrincipalId $principal): array;

    /**
     * Writes it revoked, if it's still active.
     *
     * @return bool Whether it was written
     */
    public function revoke(Delegation $before, Delegation $after): bool;

    /**
     * The realm's delegation version: it grows with every change.
     */
    public function version(Realm $realm): int;
}
