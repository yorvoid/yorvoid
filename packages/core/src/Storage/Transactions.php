<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage;

/**
 * Units of work over YorvoID's storage: a change, its security log entry and its "-ing" events happen
 * together or not at all, and "-ed" events run only once the change is committed (PLT-042).
 *
 * Work joins a transaction that is already open, also one the application opened: then everything
 * commits or rolls back with the application's own changes, and after-commit callbacks wait for that.
 *
 * @api
 */
interface Transactions
{
    /**
     * Runs the work in a transaction, joining one that is already open.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function run(\Closure $work): mixed;

    /**
     * Runs the callback once the outermost transaction has committed, or now if none is open. If the
     * transaction rolls back, the callback never runs.
     *
     * @param \Closure(): void $callback
     */
    public function afterCommit(\Closure $callback): void;
}
