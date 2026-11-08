<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\DecisionLog;

use YorvoID\Core\Realm;

/**
 * Where decisions are recorded: append-only, unchained, and without any lock, so recording never
 * makes a realm's decisions wait for each other or for its security log (ADR-09).
 *
 * @api
 */
interface DecisionLogStore
{
    /**
     * Records them, in one write where the store can.
     *
     * @param list<DecisionRecord> $records
     */
    public function add(array $records): void;

    /**
     * @throws \UnexpectedValueException When the stored record is malformed
     */
    public function find(Realm $realm, string $id): ?DecisionRecord;

    /**
     * Removes every record decided before the time, in every realm: what the security log cites stays
     * cited by its hash.
     *
     * @return int How many were removed
     */
    public function prune(\DateTimeImmutable $before): int;
}
