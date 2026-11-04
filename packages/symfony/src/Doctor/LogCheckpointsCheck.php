<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use Psr\Clock\ClockInterface;
use YorvoID\Core\Log\Checkpoint\FileCheckpoints;
use YorvoID\Core\Realm;

/**
 * Whether the security log's checkpoints are taken (AUD-003): without them, someone who can write the
 * database can cut entries off the end, or rewrite the chain, unnoticed. Outside development only.
 *
 * @internal
 */
final readonly class LogCheckpointsCheck implements Check
{
    /** A checkpoint older than this means cron stopped taking them. */
    private const int STALE = 86_400;

    public function __construct(
        private ?string $keys,
        private ?string $file,
        private bool $logger,
        private Realm $realm,
        private ClockInterface $clock,
        private bool $debug,
    ) {
    }

    public function findings(): iterable
    {
        if ($this->debug) {
            return;
        }

        if (null === $this->keys || (null === $this->file && !$this->logger)) {
            yield Finding::warning('The security log has no checkpoints: someone who can write the database could cut off its end, or rewrite it, unnoticed.', 'Set yorvoid.keys.file and yorvoid.log.checkpoint_file (or checkpoint_logger), and run yorvoid:log:checkpoint from cron.', 'concepts/security-log.md#checkpoints');

            return;
        }

        if (null === $this->file) {
            return;
        }

        $latest = null;

        foreach (new FileCheckpoints($this->file)->read($this->realm) as $checkpoint) {
            $latest = $checkpoint->takenAt;
        }

        if (null === $latest || $this->clock->now()->getTimestamp() - $latest->getTimestamp() > self::STALE) {
            yield Finding::warning(\sprintf('The newest checkpoint of the realm "%s" is %s.', $this->realm->name, null === $latest ? 'missing' : 'from '.$latest->format(\DATE_ATOM)), 'Run yorvoid:log:checkpoint from cron, every few minutes.', 'concepts/security-log.md#checkpoints');
        }
    }
}
