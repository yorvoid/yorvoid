<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\ServiceProviderInterface;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Symfony\Doctor\Check;
use YorvoID\Symfony\Doctor\Finding;
use YorvoID\Symfony\Doctor\Reports;

/**
 * Checks YorvoID's setup in the application, and says how to fix each problem (PLT-081): the
 * configuration, the database and its migrations, the clock, the assurance level, the cookies, the
 * mailer and the flow pages.
 *
 * @internal
 */
#[AsCommand('yorvoid:doctor', 'Check YorvoID\'s setup, and how to fix each problem')]
final class DoctorCommand extends Command
{
    /**
     * @param ServiceProviderInterface<Check> $checks By name, in the order to run them
     */
    public function __construct(
        private readonly ServiceProviderInterface $checks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Fail on warnings too, e.g. in a deployment pipeline');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $problems = 0;
        $warnings = 0;

        foreach (array_keys($this->checks->getProvidedServices()) as $name) {
            $findings = $this->findings($name);
            $title = $name.$this->summary($name);

            if ([] === $findings) {
                $io->writeln(\sprintf(' <info>✔</info> %s', $title));

                continue;
            }

            $io->writeln(\sprintf(' %s %s', array_any($findings, static fn (Finding $finding): bool => $finding->problem) ? '<error>✘</error>' : '<comment>!</comment>', $title));

            foreach ($findings as $finding) {
                $finding->problem ? ++$problems : ++$warnings;
                $io->writeln([
                    \sprintf('   %s %s', $finding->problem ? '<error>Problem:</error>' : '<comment>Warning:</comment>', $finding->what),
                    \sprintf('   How to fix it: %s', $finding->fix),
                    \sprintf('   Documentation: %s', $finding->url()),
                ]);
            }
        }

        if (0 === $problems && 0 === $warnings) {
            $io->success('YorvoID\'s setup is ready.');

            return Command::SUCCESS;
        }

        $summary = \sprintf('%d %s, %d %s.', $problems, 1 === $problems ? 'problem' : 'problems', $warnings, 1 === $warnings ? 'warning' : 'warnings');

        if ($problems > 0) {
            $io->error($summary);

            return Command::FAILURE;
        }

        $io->warning($summary);

        return true === $input->getOption('strict') ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * What a check that reports says, after its name.
     */
    private function summary(string $name): string
    {
        try {
            $check = $this->checks->get($name);
        } catch (\Throwable) {
            // The findings report it.
            return '';
        }

        return $check instanceof Reports ? ': '.$check->summary() : '';
    }

    /**
     * @return list<Finding>
     */
    private function findings(string $name): array
    {
        try {
            return [...$this->checks->get($name)->findings()];
        } catch (IntegratorError $error) {
            return [Finding::of($error)];
        }
    }
}
