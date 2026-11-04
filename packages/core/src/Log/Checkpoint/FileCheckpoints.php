<?php

declare(strict_types=1);

namespace YorvoID\Core\Log\Checkpoint;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Realm;

/**
 * Checkpoints in a file of their own, one JSON object a line, only ever appended to: put it where the
 * application's database user can't reach, ideally on another disk or host (a mounted, append-only
 * share), and keep it with the database's backups.
 *
 * @api
 */
final readonly class FileCheckpoints implements CheckpointSink, CheckpointSource
{
    public function __construct(
        private string $path,
    ) {
    }

    public function write(Checkpoint $checkpoint): void
    {
        $handle = @fopen($this->path, 'a');

        if (false === $handle) {
            throw new IntegratorError(\sprintf('The checkpoint file "%s" can\'t be opened for appending.', $this->path), 'Create its directory, and let the user that takes checkpoints write to it.', 'concepts/security-log.md#checkpoints');
        }

        try {
            flock($handle, \LOCK_EX);
            $line = json_encode($checkpoint->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n";

            if (\strlen($line) !== fwrite($handle, $line) || !fflush($handle) || !fsync($handle)) {
                throw new IntegratorError(\sprintf('The checkpoint couldn\'t be written to "%s".', $this->path), 'Check the disk has space, and that the file is writable.', 'concepts/security-log.md#checkpoints');
            }
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    public function read(Realm $realm): iterable
    {
        $handle = is_file($this->path) ? @fopen($this->path, 'r') : false;

        if (false === $handle) {
            return;
        }

        try {
            while (false !== ($line = fgets($handle))) {
                $checkpoint = Checkpoint::fromArray(json_decode($line, true));

                // A line that isn't a checkpoint is one; verification finds it unsigned.
                $checkpoint ??= new Checkpoint($realm, \PHP_INT_MAX, '', new \DateTimeImmutable('@0'), '', '');

                if ($checkpoint->realm->equals($realm)) {
                    yield $checkpoint;
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
