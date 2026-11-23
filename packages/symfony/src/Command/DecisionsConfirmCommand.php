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
use YorvoID\Core\Authorization\Confirmation;
use YorvoID\Core\Authorization\DecisionLog\DecisionConfirmations;
use YorvoID\Core\Authorization\DecisionLog\DecisionLogStore;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Realm;

/**
 * Whether a decision read an attribute with a value (M-9): what its record holds, compared with the value
 * given, never printing the record's MAC or the key. Each confirmation is a security-log entry.
 *
 * @internal
 */
#[AsCommand('yorvoid:decisions:confirm', 'Confirm whether a recorded decision read an attribute with a value')]
final class DecisionsConfirmCommand extends Command
{
    public function __construct(
        private readonly DecisionLogStore $decisions,
        private readonly DecisionConfirmations $confirmations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('decision', InputArgument::REQUIRED, 'The decision\'s ID');
        $this->addArgument('attribute', InputArgument::REQUIRED, 'The attribute\'s name, e.g. "acme.department"');
        $this->addArgument('value', InputArgument::REQUIRED, 'The value to confirm: text, or JSON with --json (a number, true, false, a list of text)');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Read the value as JSON');
        $this->addOption('realm', null, InputOption::VALUE_REQUIRED, 'The decision\'s realm', Realm::default()->name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $realm = new Realm(Options::string($input->getOption('realm')));
        $id = Options::string($input->getArgument('decision'));
        $value = Options::string($input->getArgument('value'));

        if (true === $input->getOption('json')) {
            $value = json_decode($value, true);

            if (!\is_int($value) && !\is_bool($value) && !\is_string($value) && !(\is_array($value) && array_is_list($value) && [] === array_filter($value, static fn (mixed $one): bool => !\is_string($one)))) {
                $io->error('With --json, the value is a number, true, false, text in quotes, or a list of texts.');

                return Command::FAILURE;
            }
        }

        $record = $this->decisions->find($realm, $id);

        if (null === $record) {
            $io->error(\sprintf('The realm "%s" has no decision %s: it was never recorded, or it\'s pruned.', $realm->name, $id));

            return Command::FAILURE;
        }

        /** @var string|int|bool|list<string> $value */
        $result = $this->confirmations->confirm($record, Options::string($input->getArgument('attribute')), $value, new Operator(), new Origin(OriginKind::Console, 'yorvoid:decisions:confirm'));
        $output->writeln($result->value);

        return Confirmation::Matches === $result ? Command::SUCCESS : Command::FAILURE;
    }
}
