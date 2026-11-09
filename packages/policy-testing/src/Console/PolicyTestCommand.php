<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YorvoID\PolicyTesting\PolicyResult;
use YorvoID\PolicyTesting\PolicySuite;
use YorvoID\PolicyTesting\PolicyTests;

/**
 * Runs the policy's cases and reports each (AUZ-043); fails when one doesn't pass. With
 * --format=json, for CI.
 *
 * @internal
 */
#[AsCommand('yorvoid:policy:test', 'Checks that the policy decides as its cases say')]
final class PolicyTestCommand extends Command
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
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');

        if ('text' !== $format && 'json' !== $format) {
            $output->writeln(\sprintf('<error>The format "%s" is neither text nor json.</error>', \is_string($format) ? $format : get_debug_type($format)));

            return self::INVALID;
        }

        $suite = ($this->suite)();
        $results = new PolicyTests()->run($suite->policy, $suite->all());
        $failed = \count(array_filter($results, static fn (PolicyResult $result): bool => !$result->passed()));

        if ('json' === $format) {
            $output->writeln(json_encode([
                'cases' => array_map(static fn (PolicyResult $result): array => [
                    'description' => $result->case->description,
                    'passed' => $result->passed(),
                    'expected' => $result->expected,
                    'verdict' => $result->decision?->verdict->value,
                    'failure' => $result->failure,
                ], $results),
                'passed' => \count($results) - $failed,
                'failed' => $failed,
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        } else {
            foreach ($results as $result) {
                $output->writeln($result->passed() ? \sprintf('<info>✔</info> %s', $result->case->description) : \sprintf('<error>✘</error> %s', $result->failure));
            }

            $output->writeln(\sprintf('%d cases, %d failed.', \count($results), $failed));
        }

        return 0 === $failed ? self::SUCCESS : self::FAILURE;
    }
}
