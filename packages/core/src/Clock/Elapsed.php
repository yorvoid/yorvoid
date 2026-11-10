<?php

declare(strict_types=1);

namespace YorvoID\Core\Clock;

/**
 * Elapsed time that can't go backwards (XP-14): what a limit on a duration is measured with, e.g. how
 * long a node may decide offline, so that changing the wall clock can't stretch it. It's not the PSR
 * clock: that gives the moment decisions read, which can be set; this gives only how long since a mark.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an extension's offline decisions use it: it may change
 *               in a minor release ([compatibility](docs/compatibility.md)).
 */
interface Elapsed
{
    /**
     * A mark of now, to measure from later, e.g. when a link was lost.
     */
    public function mark(): Instant;

    /**
     * The nanoseconds since the mark, or null when it can't tell: a mark of another boot, of another
     * process where marks are per process, or ahead of now. Never a guess, and never negative.
     */
    public function since(Instant $mark): ?int;
}
