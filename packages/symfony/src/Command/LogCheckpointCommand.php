<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\Checkpoint\Checkpoints;
use YorvoID\Core\Log\Checkpoint\CheckpointSink;
use YorvoID\Core\Log\Checkpoint\FileCheckpoints;
use YorvoID\Core\Log\Checkpoint\LoggerCheckpoints;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Realm;
use YorvoID\Symfony\Key\KeyFiles;

/**
 * Takes a signed checkpoint of a realm's security log (AUD-003): run it from cron, every few minutes.
 *
 * @internal
 */
#[AsCommand('yorvoid:log:checkpoint', 'Sign where the security log stands, and keep it outside the database')]
final class LogCheckpointCommand extends Command
{
    public function __construct(
        private readonly SecurityLogStore $store,
        private readonly SecurityLog $log,
        private readonly KeyFiles $keys,
        private readonly ?string $file,
        private readonly bool $toLogger,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    /**
     * The checkpoints as configured: signed with the realm's keys, to the file and the logger.
     */
    public static function checkpoints(SecurityLogStore $store, SecurityLog $log, KeyFiles $keys, ?string $file, bool $toLogger, ?LoggerInterface $logger): Checkpoints
    {
        $sinks = array_values(array_filter([null === $file ? null : new FileCheckpoints($file), $toLogger && null !== $logger ? new LoggerCheckpoints($logger) : null]));

        if (!$keys->isConfigured() || [] === $sinks) {
            throw new IntegratorError('Checkpoints of the security log aren\'t configured.', 'Set yorvoid.keys.file (yorvoid:keys:rotate creates it), and yorvoid.log.checkpoint_file or checkpoint_logger.', 'concepts/security-log.md#checkpoints');
        }

        /* @var list<CheckpointSink> $sinks */
        return new Checkpoints($store, $log, $keys->rings(), $sinks);
    }

    protected function configure(): void
    {
        $this->addOption('realm', null, InputOption::VALUE_REQUIRED, 'The realm', Realm::default()->name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $realm = new Realm(Options::string($input->getOption('realm')));
        $checkpoint = self::checkpoints($this->store, $this->log, $this->keys, $this->file, $this->toLogger, $this->logger)->take($realm);
        $io = new SymfonyStyle($input, $output);

        if (null === $checkpoint) {
            $io->writeln(\sprintf('The realm "%s" has no entries yet: nothing to checkpoint.', $realm->name));
        } else {
            $io->writeln(\sprintf('Checkpointed entry %d of the realm "%s", signed with the key "%s".', $checkpoint->sequence, $realm->name, $checkpoint->keyId));
        }

        return Command::SUCCESS;
    }
}
