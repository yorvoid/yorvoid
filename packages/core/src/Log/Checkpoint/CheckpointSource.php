<?php

declare(strict_types=1);

namespace YorvoID\Core\Log\Checkpoint;

use YorvoID\Core\Realm;

/**
 * Checkpoints read back, to verify a realm's log against them.
 *
 * @api
 */
interface CheckpointSource
{
    /**
     * @return iterable<Checkpoint> The realm's, in the order they were taken
     */
    public function read(Realm $realm): iterable;
}
