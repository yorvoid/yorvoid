<?php

declare(strict_types=1);

namespace YorvoID\Core\Log\Checkpoint;

use Psr\Log\LoggerInterface;

/**
 * Checkpoints handed to the application's logger, at "notice", as "yorvoid.log.checkpoint" with the
 * checkpoint in its context: for a log pipeline that ships them off the server. Verifying against them
 * means reading them back from where they end up, into a CheckpointSource.
 *
 * @api
 */
final readonly class LoggerCheckpoints implements CheckpointSink
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function write(Checkpoint $checkpoint): void
    {
        $this->logger->notice('yorvoid.log.checkpoint', $checkpoint->toArray());
    }
}
