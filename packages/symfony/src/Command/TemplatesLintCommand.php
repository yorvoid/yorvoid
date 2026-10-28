<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use YorvoID\Symfony\Templates\Samples;
use YorvoID\Symfony\Templates\TemplateLint;

/**
 * Checks the flow pages, with the application's templates, for what each step needs (UX-014).
 *
 * @internal
 */
#[AsCommand('yorvoid:templates:lint', 'Check the flow pages, with your templates, for what each step needs')]
final class TemplatesLintCommand extends Command
{
    public function __construct(
        private readonly TemplateLint $lint,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('locale', null, InputOption::VALUE_REQUIRED, 'The language to render the pages in', 'en');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $violations = $this->lint->lint(Options::string($input->getOption('locale')));

        if ([] === $violations) {
            $io->success(\sprintf('%d pages checked: each has what its step needs.', \count(Samples::all())));

            return Command::SUCCESS;
        }

        $io->error(\sprintf('%d problems in the flow pages:', \count($violations)));
        $io->listing($violations);

        return Command::FAILURE;
    }
}
