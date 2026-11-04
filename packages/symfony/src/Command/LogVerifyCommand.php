<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Log\Checkpoint\Checkpoints;
use YorvoID\Core\Log\Checkpoint\FileCheckpoints;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Realm;

/**
 * Verifies a realm's security log: its chain, and, with a checkpoint file, against the checkpoints.
 *
 * @internal
 */
#[AsCommand('yorvoid:log:verify', 'Verify the security log\'s chain, and against its checkpoints')]
final class LogVerifyCommand extends Command
{
    public function __construct(
        private readonly SecurityLogStore $store,
        private readonly SecurityLog $log,
        private readonly ?string $keys,
        private readonly ?string $file,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('realm', null, InputOption::VALUE_REQUIRED, 'The realm', Realm::default()->name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $realm = new Realm(Options::string($input->getOption('realm')));
        $withCheckpoints = null !== $this->keys && null !== $this->file;
        $verification = $withCheckpoints
            ? new Checkpoints($this->store, $this->log, KeyRing::fromFile($this->keys), [])->verify($realm, new FileCheckpoints($this->file)->read($realm))
            : $this->log->verify($realm);

        if (!$verification->isIntact()) {
            $io->error((string) $verification->problem);

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d entries of the realm "%s" are intact%s.', $verification->entriesChecked, $realm->name, $withCheckpoints ? ', and match their checkpoints' : ''));

        if (!$withCheckpoints) {
            $io->note('Without checkpoints, someone who can write the database could have cut off the end, or rewritten the chain: configure yorvoid.log.checkpoint_file.');
        }

        return Command::SUCCESS;
    }
}
