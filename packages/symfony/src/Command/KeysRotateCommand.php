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
        private readonly ?string $file,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('remove', null, InputOption::VALUE_REQUIRED, 'Remove this verify-only key, once nothing it signed still needs checking');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (null === $this->file) {
            $io->error('YorvoID has no key file to rotate: set yorvoid.keys.file, e.g. to "/run/secrets/yorvoid-keys.json".');

            return Command::FAILURE;
        }

        try {
            [$ring, $done] = $this->step($this->file, $input->getOption('remove'));
            $ring->toFile($this->file);
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
     * @return array{KeyRing, string}
     */
    private function step(string $file, mixed $remove): array
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
                return [$ring->activating($key->id), \sprintf('%s signs now, and %s only verifies. Once nothing %2$s signed still needs checking, run: yorvoid:keys:rotate --remove %2$s', $key->id, $old->id)];
            }
        }

        $new = SigningKey::generate($this->newId(array_map(static fn (SigningKey $key): string => $key->id, $keys)), KeyStatus::VerifyOnly);

        return [$ring->with($new), \sprintf('Added %s, verify-only. Deploy the key file everywhere signatures are checked, then run yorvoid:keys:rotate again to make it active.', $new->id)];
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
