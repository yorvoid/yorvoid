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

    /**
     * Runs the callback once the outermost transaction has ended, committed or rolled back, or now if
     * none is open: for what must happen whatever the caller's transaction does, e.g. disabling a
     * passkey a refused sign-in showed was copied (D-42). The callback runs in no transaction; it opens
     * one of its own. A process that ends before then loses it.
     *
     * Where the end of a transaction can't be seen (a connection without YorvoID's middleware), it runs
     * now, inside the transaction, and is undone with it.
     *
     * @param \Closure(): void $callback
     */
    public function afterEnd(\Closure $callback): void;
}
