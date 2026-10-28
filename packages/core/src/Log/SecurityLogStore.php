<?php

declare(strict_types=1);

namespace YorvoID\Core\Log;

use YorvoID\Core\Realm;

/**
 * Where the security log is kept. Appending is serialized per realm: each entry gets the next sequence
 * number and the previous entry's hash, and two appends can never get the same number, also from
 * different processes.
 *
 * @api
 */
interface SecurityLogStore
{
    /**
     * Appends the entry the callback builds from the next sequence number and the previous entry's
     * hash (null for a realm's first entry). Called inside a transaction; the realm's log stays locked
     * for other appends until it ends.
     *
     * @param \Closure(int, ?string): LogEntry $build
     */
    public function append(Realm $realm, \Closure $build): LogEntry;

    /**
     * @return iterable<LogEntry> In order of sequence, from the given number
     */
    public function entries(Realm $realm, int $fromSequence): iterable;

    /**
     * @return array{sequence: int, hash: string}|null The last entry's sequence and hash; null without entries
     */
    public function head(Realm $realm): ?array;
}
