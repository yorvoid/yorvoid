<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Key;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use YorvoID\Core\Crypto\CryptoPolicy;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\Checkpoint\Checkpoints;
use YorvoID\Core\Log\Checkpoint\FileCheckpoints;
use YorvoID\Core\Log\Checkpoint\LoggerCheckpoints;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\SecurityLogStore;

/**
 * The security log's checkpoints as the bundle configures them (AUD-003): signed with the realms' keys,
 * kept in the checkpoint file and given to the logger, verified as the crypto policy allows, and a
 * failed one raising the audit alert.
 *
 * @internal
 */
final readonly class ConfiguredCheckpoints
{
    public function __construct(
        private SecurityLogStore $store,
        private SecurityLog $log,
        public KeyFiles $keys,
        private ?string $file,
        private bool $toLogger,
        private ClockInterface $clock,
        private CryptoPolicy $crypto,
        private EventDispatcherInterface $events,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Whether checkpoints are taken: keys, and somewhere to keep them.
     */
    public function isConfigured(): bool
    {
        return $this->keys->isConfigured() && (null !== $this->file || ($this->toLogger && null !== $this->logger));
    }

    /**
     * Where the checkpoints are read back from: the checkpoint file, if there's one.
     */
    public function file(): ?FileCheckpoints
    {
        return null === $this->file ? null : new FileCheckpoints($this->file);
    }

    /**
     * @throws IntegratorError When checkpoints aren't configured
     */
    public function checkpoints(): Checkpoints
    {
        if (!$this->isConfigured()) {
            throw new IntegratorError('Checkpoints of the security log aren\'t configured.', 'Set yorvoid.keys.file (yorvoid:keys:rotate creates it), and yorvoid.log.checkpoint_file or checkpoint_logger.', 'concepts/security-log.md#checkpoints');
        }

        $sinks = array_values(array_filter([$this->file(), $this->toLogger && null !== $this->logger ? new LoggerCheckpoints($this->logger) : null]));

        return new Checkpoints($this->store, $this->log, $this->keys->rings(), $sinks, $this->clock, $this->crypto, $this->events, $this->logger ?? new NullLogger());
    }

    public function crypto(): CryptoPolicy
    {
        return $this->crypto;
    }
}
