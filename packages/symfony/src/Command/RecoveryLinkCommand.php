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
use YorvoID\Authn\Recovery\IssueRecoveryLink;
use YorvoID\Authn\Recovery\RecoveryLinks;
use YorvoID\Authn\Session\InactivePrincipal;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;

/**
 * Prints a recovery link for someone who lost access (ATN-033, PLT-043): for whoever has the server's
 * shell, e.g. Quire Server's "owner:recover".
 *
 * @internal
 */
#[AsCommand('yorvoid:recovery-link', 'Print a recovery link for someone who lost access')]
final class RecoveryLinkCommand extends Command
{
    public function __construct(
        private readonly RecoveryLinks $links,
        private readonly Principals $principals,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Their email address');
        $this->addOption('realm', null, InputOption::VALUE_REQUIRED, 'Their realm', Realm::default()->name);
        $this->addOption('url', null, InputOption::VALUE_REQUIRED, 'The recovery page, with {token} where the token goes, e.g. "https://example.com/recover/{token}"');
        $this->addOption('lifetime', null, InputOption::VALUE_REQUIRED, 'How long the link works', '15m');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $realm = new Realm(Options::string($input->getOption('realm')));
        $principal = $this->principals->findByIdentifier($realm, IdentifierKind::Email, Options::string($input->getArgument('email')));

        if (null === $principal) {
            $io->error('No one in the realm has that email address.');

            return Command::FAILURE;
        }

        try {
            $link = $this->links->issue(new IssueRecoveryLink($realm, $principal->id, Duration::parse(Options::string($input->getOption('lifetime')))), new Origin(OriginKind::Console, 'yorvoid:recovery-link'));
        } catch (InactivePrincipal $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $url = $input->getOption('url');
        $io->writeln(\is_string($url) ? str_replace('{token}', rawurlencode($link->secret->reveal()), $url) : $link->secret->reveal());

        return Command::SUCCESS;
    }
}
