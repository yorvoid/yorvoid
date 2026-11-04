<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use YorvoID\Core\Principal\Storage\IdentifierRenormalization;

/**
 * Brings stored identifiers up to this version's rules for comparing them, after an upgrade that says
 * to run it (the changelog does).
 *
 * @internal
 */
#[AsCommand('yorvoid:identifiers:normalize', 'Compare stored identifiers by this version\'s rules, after an upgrade that changed them')]
final class IdentifiersNormalizeCommand extends Command
{
    public function __construct(
        private readonly IdentifierRenormalization $renormalization,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list what would change');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');
        $report = $this->renormalization->run($dryRun);

        foreach ($report['changed'] as $change) {
            $io->writeln(\sprintf('%s in the realm %s: %s → %s', $dryRun ? 'Would change' : 'Changed', $change['realm'], $change['from'], $change['to']));
        }

        foreach ($report['conflicts'] as $conflict) {
            $io->warning(\sprintf('In the realm %s, the email address %s is now the same as another one (%s): left as it was. Decide who keeps it, and change or remove the other.', $conflict['realm'], $conflict['value'], $conflict['with']));
        }

        foreach ($report['invalid'] as $invalid) {
            $io->warning(\sprintf('In the realm %s, the email address %s isn\'t valid by this version\'s rules: left as it was. Change it.', $invalid['realm'], $invalid['value']));
        }

        $io->success(\sprintf('%d email address(es) %s.', \count($report['changed']), $dryRun ? 'would change' : 'changed'));

        return [] === $report['conflicts'] && [] === $report['invalid'] ? Command::SUCCESS : Command::FAILURE;
    }
}
