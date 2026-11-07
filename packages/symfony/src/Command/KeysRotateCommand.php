<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Command;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Key\KeyStatus;
use YorvoID\Core\Key\SigningKey;
use YorvoID\Core\Realm;
use YorvoID\Symfony\Key\KeyFiles;

/**
 * Rotates the signing keys without downtime (OPS-003), one step per run, saying what comes next: a
 * new verify-only key, deployed everywhere signatures are checked; then that key active and the old
 * one verify-only; then, with --remove, the old one gone. The first run creates the key ring.
 *
 * @internal
 */
#[AsCommand('yorvoid:keys:rotate', 'Take the next step in rotating YorvoID\'s signing keys')]
final class KeysRotateCommand extends Command
{
    public function __construct(
        private readonly KeyFiles $keys,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('remove', null, InputOption::VALUE_REQUIRED, 'Remove this verify-only key, once nothing it signed still needs checking');
        $this->addOption('public', null, InputOption::VALUE_NONE, 'Print the public key set to deploy where signatures are checked, and change nothing');
        $this->addOption('realm', null, InputOption::VALUE_REQUIRED, 'The realm whose keys to rotate, with yorvoid.keys.per_realm', Realm::default()->name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $realm = new Realm(Options::string($input->getOption('realm')));

        if (!$this->keys->isPerRealm() && $input->hasParameterOption('--realm')) {
            $io->error('Every realm shares one key ring: --realm needs yorvoid.keys.per_realm.');

            return Command::FAILURE;
        }

        $file = $this->keys->path($realm);

        if (null === $file) {
            $io->error('YorvoID has no key file to rotate: set yorvoid.keys.file, e.g. to "/run/secrets/yorvoid-keys.json".');

            return Command::FAILURE;
        }

        if (true === $input->getOption('public')) {
            try {
                $output->writeln(KeyRing::fromFile($file)->toJwks()->reveal());
            } catch (IntegratorError $error) {
                $io->error([$error->problem, 'How to fix it: '.$error->fix]);

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        try {
            [$ring, $done] = $this->step($file, $input->getOption('remove'), $this->keys->isPerRealm() ? ' --realm '.$realm->name : '');
            $ring->toFile($file);
        } catch (IntegratorError $error) {
            $io->error([$error->problem, 'How to fix it: '.$error->fix]);

            return Command::FAILURE;
        }

        $io->table(['Key', 'Status'], array_map(static fn (SigningKey $key): array => [$key->id, $key->status->value], $ring->keys()));
        $io->success($done);

        return Command::SUCCESS;
    }

    /**
     * The next step: the ring after it, and what to say about it.
     *
     * @param string $realm The --realm option to say, if any
     *
     * @return array{KeyRing, string}
     */
    private function step(string $file, mixed $remove, string $realm): array
    {
        if (!file_exists($file)) {
            $key = SigningKey::generate($this->newId([]));

            return [new KeyRing([$key]), \sprintf('Created the key ring in %s, with %s active.', $file, $key->id)];
        }

        $ring = KeyRing::fromFile($file);

        if (\is_string($remove)) {
            return [$ring->without($remove), \sprintf('Removed %s: what it signed no longer verifies.', $remove)];
        }

        $old = $ring->signer();
        $keys = $ring->keys();

        // A key added after the active one, that can sign: the one the last run added.
        foreach (array_reverse($keys) as $key) {
            if ($key === $old) {
                break;
            }

            if (null !== $key->seed) {
                return [$ring->activating($key->id), \sprintf('%s signs now, and %s only verifies. Once nothing %2$s signed still needs checking, run: yorvoid:keys:rotate --remove %2$s%3$s', $key->id, $old->id, $realm)];
            }
        }

        $new = SigningKey::generate($this->newId(array_map(static fn (SigningKey $key): string => $key->id, $keys)), KeyStatus::VerifyOnly);

        return [$ring->with($new), \sprintf('Added %s, verify-only. Deploy the public key set (yorvoid:keys:rotate --public%2$s, never the key file) everywhere signatures are checked, then run yorvoid:keys:rotate%2$s again to make it active.', $new->id, $realm)];
    }

    /**
     * Today's date, numbered if a key has it already: "2026-10-29", "2026-10-29.2".
     *
     * @param list<string> $taken
     */
    private function newId(array $taken): string
    {
        $date = $this->clock->now()->format('Y-m-d');

        $id = $date;

        for ($n = 2; \in_array($id, $taken, true); ++$n) {
            $id = \sprintf('%s.%d', $date, $n);
        }

        return $id;
    }
}
