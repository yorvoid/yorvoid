<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\DecisionLog;

use YorvoID\Core\Realm;

/**
 * Where decisions are recorded: append-only, unchained, and without any lock, so recording never
 * makes a realm's decisions wait for each other or for its security log (ADR-09).
 *
 * @api
 *
 * @spi Implement it, don't call it: calling a store changes YorvoID's records without asking YorvoID
 */
interface DecisionLogStore
{
    /**
     * Records them, in one write where the store can.
     *
     * @param list<DecisionRecord> $records
     * @param bool|null            $apart   Whether they're written apart from any caller's transaction, so a
     *                                      digest covers them (D-42): true for refusals and attempts, which
     *                                      DecisionLog writes so; null for the store to tell, as for a permit
     */
    public function add(array $records, ?bool $apart = null): void;

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

    /**
     * Now, by the store's own clock, in microseconds since the epoch: what records' write times are
     * counted by (D-42).
     */
    public function now(): int;

    /**
     * The records written apart from any caller's transaction, after the first time and up to the
     * second (microseconds, the store's clock), in the order written, then by ID: what a digest covers.
     * Records written inside a caller's transaction aren't among them: their citation covers them.
     *
     * @return list<array{string, string}> Each one's ID and hash
     */
    public function written(Realm $realm, int $after, int $until): array;

    /**
     * How far the realm's records are digested in its chain: 0 before the first digest.
     */
    public function digestedUntil(Realm $realm): int;

    /**
     * Notes that the realm's records are digested up to then. Written with the digest's chain entry, in
     * the same transaction.
     */
    public function digested(Realm $realm, int $until): void;

    /**
     * The time, in microseconds, before which records were pruned: 0 if none were.
     */
    public function prunedBefore(): int;
}
