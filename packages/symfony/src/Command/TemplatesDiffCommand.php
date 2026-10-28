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
use YorvoID\Symfony\Templates\TemplatePublisher;
use YorvoID\Symfony\Templates\TemplateStatus;

/**
 * After an upgrade, what YorvoID changed in the templates the application published (UX-006): a
 * three-way comparison of the version it published, its copy, and YorvoID's now.
 *
 * @internal
 */
#[AsCommand('yorvoid:templates:diff', 'Show what YorvoID changed in the templates you published')]
final class TemplatesDiffCommand extends Command
{
    public function __construct(
        private readonly TemplatePublisher $publisher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('templates', InputArgument::IS_ARRAY, 'The templates; every published one if not given');
        $this->addOption('accept', null, InputOption::VALUE_NONE, 'Take YorvoID\'s changes: replaces the copies you didn\'t change, and marks the ones you merged by hand');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $templates = Options::strings($input->getArgument('templates'));
        $accept = true === $input->getOption('accept');
        $attention = false;

        foreach ([] === $templates ? $this->publisher->published() : $templates as $template) {
            $status = $this->publisher->status($template);
            $io->section($template);

            match ($status) {
                TemplateStatus::NotPublished => $io->writeln('Not published.'),
                TemplateStatus::UpToDate => $io->writeln('Up to date.'),
                TemplateStatus::Removed => $io->writeln('YorvoID doesn\'t have it any more: the flow doesn\'t use your copy. Remove it.'),
                TemplateStatus::Upgradable => $io->writeln('YorvoID changed it, and you didn\'t: take the new version as it is.'),
                TemplateStatus::Diverged => $io->writeln('YorvoID changed it, and so did you: merge these changes into your copy.'),
            };

            if (\in_array($status, [TemplateStatus::Upgradable, TemplateStatus::Diverged], true)) {
                $io->writeln($this->publisher->changes($template), OutputInterface::OUTPUT_RAW);
            }

            if ($accept) {
                $this->publisher->accept($template);
                $io->writeln(match ($status) {
                    TemplateStatus::Upgradable => 'Took the new version.',
                    TemplateStatus::Diverged => 'Marked as merged: your copy is now based on YorvoID\'s new version.',
                    TemplateStatus::Removed => 'Forgot the version it came from.',
                    default => '',
                });
            } else {
                $attention = $attention || \in_array($status, [TemplateStatus::Upgradable, TemplateStatus::Diverged, TemplateStatus::Removed], true);
            }
        }

        if ($attention) {
            $io->note('Run again with --accept, once you merged the changes into the copies you changed.');
        }

        return $attention ? Command::FAILURE : Command::SUCCESS;
    }
}
