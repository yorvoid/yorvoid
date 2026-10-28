<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use YorvoID\Authn\Migrations\AuthnMigrations;
use YorvoID\Core\Migrations\CoreMigrations;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Passkeys\Migrations\PasskeysMigrations;
use YorvoID\Throttle\Migrations\ThrottleMigrations;

/**
 * Creates and updates YorvoID's tables, separately from the application's own migrations (PLT-023).
 *
 * @internal
 */
#[AsCommand('yorvoid:migrate', 'Create or update YorvoID\'s tables')]
final class MigrateCommand extends Command
{
    /** @var list<Migration> */
    private array $migrations;

    /**
     * @param list<Migration>|null $migrations Every package's, in order, if not given
     */
    public function __construct(
        private readonly Database $database,
        ?array $migrations = null,
    ) {
        parent::__construct();
        $this->migrations = $migrations ?? array_merge(CoreMigrations::all(), AuthnMigrations::all(), PasskeysMigrations::all(), ThrottleMigrations::all());
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the SQL of the pending migrations, without running them');
        $this->addOption('allow-blocking', null, InputOption::VALUE_NONE, 'Run migrations that block tables while they run: in a maintenance window');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $migrator = new Migrator($this->database, $this->migrations);

        if (true === $input->getOption('dry-run')) {
            $sql = $migrator->sql();
            [] === $sql ? $io->success('YorvoID\'s tables are up to date.') : $io->writeln(array_map(static fn (string $statement): string => $statement.';', $sql));

            return Command::SUCCESS;
        }

        $ran = $migrator->migrate(true === $input->getOption('allow-blocking'));
        $io->success([] === $ran ? 'YorvoID\'s tables are up to date.' : \sprintf('Ran %d migrations: %s.', \count($ran), implode(', ', $ran)));

        return Command::SUCCESS;
    }
}
