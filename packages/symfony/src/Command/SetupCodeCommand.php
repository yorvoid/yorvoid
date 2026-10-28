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
use YorvoID\Authn\Setup\SetupCodes;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
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
        private readonly SetupCodes $setupCodes,
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
            $code = $this->setupCodes->create(new CreateSetupCode(new Realm(Options::string($input->getArgument('realm'))), Duration::parse(Options::string($input->getOption('lifetime')))), new Origin(OriginKind::Console, 'yorvoid:setup-code'));
        } catch (RealmAlreadyClaimed $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->writeln($code->reveal());

        return Command::SUCCESS;
    }
}
