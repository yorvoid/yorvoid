<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use YorvoID\Core\Realm;
use YorvoID\Symfony\Key\ConfiguredCheckpoints;

/**
 * Takes a signed checkpoint of a realm's security log (AUD-003): run it from cron, every few minutes.
 * One that can't be signed or kept raises the audit alert, and fails.
 *
 * @internal
 */
#[AsCommand('yorvoid:log:checkpoint', 'Sign where the security log stands, and keep it outside the database')]
final class LogCheckpointCommand extends Command
{
    public function __construct(
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
        $realm = new Realm(Options::string($input->getOption('realm')));
        $checkpoint = $this->checkpoints->checkpoints()->take($realm);
        $io = new SymfonyStyle($input, $output);

        if (null === $checkpoint) {
            $io->writeln(\sprintf('The realm "%s" has no entries yet: nothing to checkpoint.', $realm->name));
        } else {
            $io->writeln(\sprintf('Checkpointed entry %d of the realm "%s", signed with the key "%s".', $checkpoint->sequence, $realm->name, $checkpoint->keyId));
        }

        return Command::SUCCESS;
    }
}
