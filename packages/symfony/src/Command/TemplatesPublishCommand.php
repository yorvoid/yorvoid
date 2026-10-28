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
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Flows\Flows;
use YorvoID\Flows\Step;
use YorvoID\Symfony\Templates\TemplatePublisher;

/**
 * Copies YorvoID's flow templates into the application, to change them (UX-006).
 *
 * @internal
 */
#[AsCommand('yorvoid:templates:publish', 'Copy YorvoID\'s flow templates into the application, to change them')]
final class TemplatesPublishCommand extends Command
{
    public function __construct(
        private readonly TemplatePublisher $publisher,
        private readonly Flows $flows,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('templates', InputArgument::IS_ARRAY, 'The templates, e.g. "passkey layout"; or a flow, e.g. "yorvoid.setup", for its steps\' templates');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Replace the application\'s own');
        $this->setHelp(<<<'HELP'
            Copies the templates to templates/bundles/YorvoIDBundle/flow/, where Symfony uses them instead
            of YorvoID's, and the versions they came from to templates/bundles/YorvoIDBundle/.upstream/flow/,
            for yorvoid:templates:diff after an upgrade. Commit both.

            To change a single block rather than a whole template, don't publish it: extend YorvoID's in your
            own, {% extends '@!YorvoID/flow/passkey.html.twig' %}, and override the block.
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $names = Options::strings($input->getArgument('templates'));

        if ([] === $names) {
            $io->error('Name the templates to publish, or a flow.');
            $io->listing($this->publisher->templates());

            return Command::INVALID;
        }

        $templates = [];

        try {
            foreach ($names as $name) {
                $templates = [...$templates, ...(str_contains($name, '.') ? array_map(static fn (Step $step): string => $step->name(), $this->flows->flow($name)->steps) : [$name])];
            }

            foreach (array_unique($templates) as $template) {
                $this->publisher->publish($template, true === $input->getOption('force'));
                $io->writeln(\sprintf('Published %s', $template));
            }
        } catch (IntegratorError $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
