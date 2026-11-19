<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage;

/**
 * Something that keeps what a request read, e.g. a cache of the policy: it forgets it before work that
 * conflicted runs again, so that it decides on what's committed now (D-52).
 *
 * @api
 */
interface ForgetsWhatItRead
{
    public function reset(): void;
}
