<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Realm;
use YorvoID\Symfony\Key\ConfiguredCheckpoints;

/**
 * Verifies a realm's security log: its chain, and, with a checkpoint file, against the checkpoints.
 *
 * @internal
 */
#[AsCommand('yorvoid:log:verify', 'Verify the security log\'s chain, and against its checkpoints')]
final class LogVerifyCommand extends Command
{
    public function __construct(
        private readonly SecurityLog $log,
        private readonly ConfiguredCheckpoints $checkpoints,
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
        $file = $this->checkpoints->keys->isConfigured() ? $this->checkpoints->file() : null;
        $verification = null !== $file
            ? $this->checkpoints->checkpoints()->verify($realm, $file->read($realm))
            : $this->log->verify($realm);

        if (!$verification->isIntact()) {
            $io->error((string) $verification->problem);

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d entries of the realm "%s" are intact%s.', $verification->entriesChecked, $realm->name, null !== $file ? ', and match their checkpoints' : ''));

        if (null === $file) {
            $io->note('Without checkpoints, someone who can write the database could have cut off the end, or rewritten the chain: configure yorvoid.log.checkpoint_file.');
        }

        if ($verification->uncheckable > 0) {
            $io->note(\sprintf('%d checkpoints are signed with keys this OpenSSL can\'t verify; the anchor after them covers what they did. They can be checked in the standard crypto mode.', $verification->uncheckable));
        }

        return Command::SUCCESS;
    }
}
