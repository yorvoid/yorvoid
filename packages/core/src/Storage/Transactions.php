<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage;

use YorvoID\Core\Realm;

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
     * Locks the realm until the transaction ends, for a check over the realm as a whole and every change
     * that can break one: e.g. that it keeps an administrator, which a change of status and a change of
     * roles can both break, or that a setup claims it only while it has no active principal. It's the
     * first lock of its unit of work, before version rows, a principal's or the security log's: taking
     * it later risks a deadlock (D-52). Taking it again in the same transaction takes nothing more.
     *
     * It's a write, so that under REPEATABLE READ a transaction that waited for it fails as a conflict
     * (RetryableConflict), rather than going on with an older snapshot. Inside a transaction only.
     */
    public function lockRealm(Realm $realm): void;

    /**
     * Runs the callback just before the outermost transaction commits, inside it, if the end of the
     * transaction can be seen: then it returns true. A failed unit of work drops the callbacks it added,
     * also a savepoint the application rolled back, and if a callback fails, the transaction rolls back
     * and the commit throws: what the callbacks write is never missing from what's committed. Where the
     * commit can't be seen (no transaction open, a connection without YorvoID's middleware), it returns
     * false and runs nothing: do the work now instead. For writes that take a lock better held for as
     * short a time as possible, e.g. the security log's (D-52).
     *
     * @param \Closure(): void $callback
     */
    public function beforeCommit(\Closure $callback): bool;

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
