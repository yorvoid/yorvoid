<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Core\Duration;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Throttle\Throttle;

/**
 * Removes what YorvoID no longer needs: ended sessions, used and expired tokens, expired ceremonies,
 * throttling state that's clear, ended cool-downs, devices not used for long. Run it daily.
 *
 * @internal
 */
#[AsCommand('yorvoid:purge', 'Remove ended sessions, expired tokens and other state YorvoID no longer needs')]
final class PurgeCommand extends Command
{
    public function __construct(
        private readonly Sessions $sessions,
        private readonly OneTimeTokens $tokens,
        private readonly Passkeys $passkeys,
        private readonly Throttle $throttle,
        private readonly StepUp $stepUp,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('keep-sessions', null, InputOption::VALUE_REQUIRED, 'How long ended sessions stay listed, e.g. "30d"', '30d');
        $this->addOption('keep-devices', null, InputOption::VALUE_REQUIRED, 'How long an unused device stays known: longer than its cookie lives', '400d');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $purged = [
            'sessions' => $this->sessions->purge(Duration::parse(Options::string($input->getOption('keep-sessions')))),
            'devices' => $this->sessions->purgeDevices(Duration::parse(Options::string($input->getOption('keep-devices')))),
            'one-time tokens' => $this->tokens->purge(),
            'passkey ceremonies' => $this->passkeys->purge(),
            'throttling keys' => $this->throttle->purge(),
            'cool-downs' => $this->stepUp->purge(),
        ];

        new SymfonyStyle($input, $output)->success(implode(', ', array_map(static fn (string $what, int $count): string => \sprintf('%d %s', $count, $what), array_keys($purged), $purged)).'.');

        return Command::SUCCESS;
    }
}
