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
     * transaction rolls back, the callback never runs. It may open a transaction of its own, also after a
     * commit the application started. If it fails, the failure is logged at error and not thrown: what
     * it waited for is committed (D-43).
     *
     * @param \Closure(): void $callback
     */
    public function afterCommit(\Closure $callback): void;

    /**
     * Runs the callback once the outermost transaction has ended, committed or rolled back, or now if
     * none is open: for what must happen whatever the caller's transaction does, e.g. disabling a
     * passkey a refused sign-in showed was copied (D-42). The callback's writes are its own transaction's,
     * not the caller's. If it fails, the audit alert is raised (AuditFailed, after_end). A process that
     * ends before then loses it.
     *
     * Where the end of a transaction can't be seen (a connection without YorvoID's middleware), it runs
     * now, inside the transaction, and is undone with it.
     *
     * @param \Closure(): void $callback
     */
    public function afterEnd(\Closure $callback): void;

    /**
     * Runs the work, and again, from the start, when a transaction of its conflicted with another (a
     * deadlock, a serialization failure) and rolled back: up to 3 attempts in all, with a short random
     * wait between them. It opens no transaction itself: the work's own do, so what the work does outside
     * them, e.g. counting an attempt, stays done. What a transaction that conflicted left waiting with
     * afterCommit() or afterEnd() goes with it, and what keeps what it read (ForgetsWhatItRead) forgets
     * it. Inside a transaction that's already open, it runs once, and a conflict throws
     * RetryableConflict, for whoever opened the transaction to retry: the work of an application's
     * transaction isn't YorvoID's to run again (D-52).
     *
     * Only for work that does nothing outside the database except through afterCommit() and afterEnd(),
     * or what's fine done again: whatever else it does, it does again.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     *
     * @throws RetryableConflict After the third attempt, or at once inside an open transaction
     */
    public function retrying(\Closure $work): mixed;
}
