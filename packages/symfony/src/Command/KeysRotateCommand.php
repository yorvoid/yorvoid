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
use YorvoID\Core\Crypto\CryptoMode;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Key\KeyAlgorithm;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Key\KeyStatus;
use YorvoID\Core\Key\SigningKey;
use YorvoID\Core\Realm;
use YorvoID\Symfony\Key\ConfiguredCheckpoints;
use YorvoID\Symfony\Key\KeyFiles;

/**
 * Rotates the signing keys without downtime (OPS-003), one step per run, saying what comes next: a
 * new verify-only key, deployed everywhere signatures are checked; then that key active and the old
 * one verify-only; then, with --remove, the old one gone. The first run creates the key ring.
 *
 * A new key is the crypto policy's algorithm unless --algorithm says: ES256 in the FIPS mode, Ed25519
 * otherwise. Making a key of the other algorithm active anchors the realm's log (yorvoid:log:anchor),
 * which first verifies it against every checkpoint; if that fails, the ring stays as it was.
 *
 * @internal
 */
#[AsCommand('yorvoid:keys:rotate', 'Take the next step in rotating YorvoID\'s signing keys')]
final class KeysRotateCommand extends Command
{
    public function __construct(
        private readonly KeyFiles $keys,
        private readonly ClockInterface $clock,
        private readonly ConfiguredCheckpoints $checkpoints,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('remove', null, InputOption::VALUE_REQUIRED, 'Remove this verify-only key, once nothing it signed still needs checking');
        $this->addOption('public', null, InputOption::VALUE_NONE, 'Print the public key set to deploy where signatures are checked, and change nothing');
        $this->addOption('realm', null, InputOption::VALUE_REQUIRED, 'The realm whose keys to rotate, with yorvoid.keys.per_realm', Realm::default()->name);
        $this->addOption('algorithm', null, InputOption::VALUE_REQUIRED, 'The new key\'s algorithm, ES256 or EdDSA: the crypto policy\'s by default');
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

        $algorithm = $input->getOption('algorithm');
        $algorithm = null === $algorithm ? (CryptoMode::Fips === $this->checkpoints->crypto()->mode ? KeyAlgorithm::ES256 : KeyAlgorithm::EdDSA) : KeyAlgorithm::tryFrom(Options::string($algorithm));

        if (null === $algorithm) {
            $io->error('--algorithm is ES256 or EdDSA.');

            return Command::FAILURE;
        }

        try {
            $before = file_exists($file) ? KeyRing::fromFile($file) : null;
            [$ring, $done] = $this->step($file, $input->getOption('remove'), $this->keys->isPerRealm() ? ' --realm '.$realm->name : '', $algorithm);
            $ring->toFile($file);
            $done .= $this->anchor($before, $ring, $realm);
        } catch (IntegratorError $error) {
            $io->error([$error->problem, 'How to fix it: '.$error->fix]);

            return Command::FAILURE;
        }

        $io->table(['Key', 'Algorithm', 'Status'], array_map(static fn (SigningKey $key): array => [$key->id, $key->algorithm->value, $key->status->value], $ring->keys()));
        $io->success($done);

        return Command::SUCCESS;
    }

    /**
     * When the key that signs now is of another algorithm than the one that signed: the realm's anchor,
     * verified first. If it can't be had, the ring is put back as it was.
     *
     * @return string What to say about it
     *
     * @throws IntegratorError When the log doesn't verify, or a checkpoint can't be checked here
     */
    private function anchor(?KeyRing $before, KeyRing $after, Realm $realm): string
    {
        if (null === $before || $before->signer()->algorithm === $after->signer()->algorithm || !$this->checkpoints->isConfigured() || null === $this->checkpoints->file()) {
            return '';
        }

        $file = $this->checkpoints->file();

        try {
            $anchor = $this->checkpoints->checkpoints()->anchor($realm, $file->read($realm));
        } catch (IntegratorError $error) {
            $before->toFile((string) $this->keys->path($realm));

            throw new IntegratorError($error->problem.' The key ring is as it was.', $error->fix, $error->docs);
        }

        $others = $this->keys->isPerRealm() ? '' : ' Anchor the other realms that share these keys before moving to the FIPS mode: yorvoid:log:anchor --all.';

        return null === $anchor ? \sprintf(' The realm "%s" has no entries to anchor yet.%s', $realm->name, $others) : \sprintf(' Anchored entry %d of the realm "%s" with it.%s', $anchor->sequence, $realm->name, $others);
    }

    /**
     * The next step: the ring after it, and what to say about it.
     *
     * @param string       $realm     The --realm option to say, if any
     * @param KeyAlgorithm $algorithm A new key's
     *
     * @return array{KeyRing, string}
     */
    private function step(string $file, mixed $remove, string $realm, KeyAlgorithm $algorithm): array
    {
        if (!file_exists($file)) {
            $key = SigningKey::generate($this->newId([]), algorithm: $algorithm);

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

        $new = SigningKey::generate($this->newId(array_map(static fn (SigningKey $key): string => $key->id, $keys)), KeyStatus::VerifyOnly, $algorithm);

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
