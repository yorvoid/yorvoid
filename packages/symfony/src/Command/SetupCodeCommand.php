<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use YorvoID\Authn\Setup\CreateSetupCode;
use YorvoID\Authn\Setup\RealmAlreadyClaimed;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Realm;

/**
 * Prints a setup code that claims a fresh realm (ATN-034): whoever installs the application runs it.
 *
 * @internal
 */
#[AsCommand('yorvoid:setup-code', 'Create the code that claims a fresh installation')]
final class SetupCodeCommand extends Command
{
    public function __construct(
        private readonly Operations $operations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('realm', InputArgument::OPTIONAL, 'The realm to claim', Realm::default()->name);
        $this->addOption('lifetime', null, InputOption::VALUE_REQUIRED, 'How long the code works, e.g. "24h"', '24h');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $code = $this->operations->run(new CreateSetupCode(new Realm(Options::string($input->getArgument('realm'))), Duration::parse(Options::string($input->getOption('lifetime')))), new Operator(), new Origin(OriginKind::Console, 'yorvoid:setup-code'));
        } catch (RealmAlreadyClaimed $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        // The code alone on standard output, for scripts; what it is, for people, beside it.
        $io->writeln($code->reveal());
        $io->getErrorStyle()->note(\sprintf('That\'s the setup code for the realm "%s": it\'s a secret. Give it only to whoever claims the realm; it works once, for %s. Only its hash is stored: if it\'s lost, create another.', Options::string($input->getArgument('realm')), Options::string($input->getOption('lifetime'))));

        return Command::SUCCESS;
    }
}
