<?php

declare(strict_types=1);

namespace YorvoID\Core\Log\Checkpoint;

/**
 * Where checkpoints go: anywhere the application's database user can't change, e.g. a file on another
 * disk or host, or a log pipeline that ships elsewhere.
 *
 * @api
 */
interface CheckpointSink
{
    public function write(Checkpoint $checkpoint): void;
}
