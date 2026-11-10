<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use YorvoID\Core\Crypto\CryptoMode;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Realm;
use YorvoID\Symfony\Key\ConfiguredCheckpoints;

/**
 * Anchors a realm's security log: verifies it against every checkpoint, then signs an anchor with the
 * active key. After moving to ES256 keys, every realm with Ed25519 checkpoints needs one before the FIPS
 * mode, where those can't be checked. In the standard mode only, where they can.
 *
 * @internal
 */
#[AsCommand('yorvoid:log:anchor', 'Verify the security log against all its checkpoints, then sign an anchor')]
final class LogAnchorCommand extends Command
{
    public function __construct(
        private readonly ConfiguredCheckpoints $checkpoints,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('realm', null, InputOption::VALUE_REQUIRED, 'The realm', Realm::default()->name);
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Every realm the checkpoint file has checkpoints of');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $this->checkpoints->file();

        if (CryptoMode::Fips === $this->checkpoints->crypto()->mode) {
            $io->error('Anchoring checks every checkpoint, which the FIPS mode may not be able to: anchor in the standard crypto mode, before moving to it.');

            return Command::FAILURE;
        }

        if (null === $file) {
            $io->error('Anchoring checks the log against its checkpoints, read back from yorvoid.log.checkpoint_file: set it.');

            return Command::FAILURE;
        }

        $realms = true === $input->getOption('all') ? $file->realms() : [new Realm(Options::string($input->getOption('realm')))];

        foreach ($realms as $realm) {
            try {
                $anchor = $this->checkpoints->checkpoints()->anchor($realm, $file->read($realm));
            } catch (IntegratorError $error) {
                $io->error([$error->problem, 'How to fix it: '.$error->fix]);

                return Command::FAILURE;
            }

            $io->writeln(null === $anchor
                ? \sprintf('The realm "%s" has no entries yet: nothing to anchor.', $realm->name)
                : \sprintf('Anchored entry %d of the realm "%s", signed with the key "%s".', $anchor->sequence, $realm->name, $anchor->keyId));
        }

        return Command::SUCCESS;
    }
}
