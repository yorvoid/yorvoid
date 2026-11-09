<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YorvoID\PolicyTesting\Mutation\Mutant;
use YorvoID\PolicyTesting\Mutation\PolicyMutation;
use YorvoID\PolicyTesting\PolicyResult;
use YorvoID\PolicyTesting\PolicySuite;

/**
 * Breaks the policy one change at a time and reports what no case catches (AUZ-045); fails below the
 * score asked for, or when a case fails on the policy itself. With --format=json, for CI.
 *
 * @internal
 */
#[AsCommand('yorvoid:policy:mutate', 'Reports the changes to the policy that no case catches')]
final class PolicyMutateCommand extends Command
{
    /**
     * @param \Closure(): PolicySuite $suite
     */
    public function __construct(
        private readonly \Closure $suite,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('min-score', null, InputOption::VALUE_REQUIRED, 'The share of mutants the cases must catch, from 0 to 100', '100')
            ->addOption('ignore', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'The ID of an equivalent mutant, left out of the score')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        $given = $input->getOption('min-score');
        $min = \is_string($given) && is_numeric($given) ? (float) $given : -1.0;
        /** @var list<string> $ignore An option of values */
        $ignore = $input->getOption('ignore');

        if ('text' !== $format && 'json' !== $format) {
            $output->writeln(\sprintf('<error>The format "%s" is neither text nor json.</error>', \is_string($format) ? $format : get_debug_type($format)));

            return self::INVALID;
        }

        if ($min < 0.0 || $min > 100.0) {
            $output->writeln('<error>The minimum score is a number from 0 to 100.</error>');

            return self::INVALID;
        }

        $suite = ($this->suite)();
        $report = new PolicyMutation()->run($suite->policy, $suite->all(), $ignore);
        $passes = [] === $report->failing && $report->score() >= $min;
        $described = static fn (Mutant $mutant): array => ['id' => $mutant->id(), 'description' => $mutant->description(), 'exercised' => $report->exercised($mutant)];

        if ('json' === $format) {
            $output->writeln(json_encode([
                'score' => round($report->score(), 2),
                'min_score' => $min,
                'failing' => array_map(static fn (PolicyResult $result): ?string => $result->failure, $report->failing),
                'killed' => $report->killed,
                'survived' => array_map($described, $report->survived),
                'ignored' => array_map($described, $report->ignored),
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

            return $passes ? self::SUCCESS : self::FAILURE;
        }

        if ([] !== $report->failing) {
            $output->writeln('<error>The cases must pass on the policy itself first:</error>');

            foreach ($report->failing as $result) {
                $output->writeln(\sprintf('✘ %s', $result->failure));
            }

            return self::FAILURE;
        }

        foreach ($report->survived as $mutant) {
            $output->writeln(\sprintf('<error>✘</error> %s: %s %s', $mutant->id(), $mutant->description(), $report->exercised($mutant) ? 'No case catches it.' : 'Not exercised: no case reaches it. Add one, or --ignore it if it\'s equivalent.'));
        }

        foreach ($report->ignored as $mutant) {
            $output->writeln(\sprintf('– %s: ignored as equivalent.', $mutant->id()));
        }

        $output->writeln(\sprintf('%d mutants: %d caught, %d survived, %d ignored. Score %s%% (at least %s%%).', \count($report->killed) + \count($report->survived) + \count($report->ignored), \count($report->killed), \count($report->survived), \count($report->ignored), round($report->score(), 2), $min));

        return $passes ? self::SUCCESS : self::FAILURE;
    }
}
