<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use YorvoID\Core\Crypto\CryptoMode;
use YorvoID\Core\Key\KeyAlgorithm;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Realm;
use YorvoID\Symfony\Key\ConfiguredCheckpoints;

/**
 * Whether the signing keys suit the crypto mode (CRY-01, AUD-003):
 *
 * - In the FIPS mode without EdDSA, an active Ed25519 key in any realm's ring can't sign checkpoints.
 * - With an ES256 key active, a realm with Ed25519 checkpoints and no anchor after them would fail
 *   verification once in the FIPS mode: said now, before the switch.
 *
 * @internal
 */
final readonly class KeyAlgorithmsCheck implements Check
{
    public function __construct(
        private ConfiguredCheckpoints $checkpoints,
        /** @var string|null The folder of per-realm key files, if they're per realm */
        private ?string $directory = null,
    ) {
    }

    public function findings(): iterable
    {
        // Without keys, there are no rings and no checkpoints to read.
        $keys = $this->checkpoints->keys;
        $crypto = $this->checkpoints->crypto();

        foreach ($this->rings() as $name => $ring) {
            $signer = $ring->signer()->algorithm;

            if (CryptoMode::Fips === $crypto->mode && !$crypto->allowsEdDsa() && KeyAlgorithm::EdDSA === $signer) {
                yield Finding::problem(\sprintf('The active signing key of %s is Ed25519, which this FIPS provider doesn\'t have: its checkpoints can\'t be taken.', $name), 'In the standard crypto mode, rotate to an ES256 key (yorvoid:keys:rotate --algorithm=ES256) and anchor the realm (yorvoid:log:anchor); then switch to the FIPS mode.', 'concepts/keys.md#moving-to-es256');
            }
        }

        $file = $this->checkpoints->file();

        if (CryptoMode::Standard !== $crypto->mode || null === $file) {
            return;
        }

        foreach ($file->realms() as $realm) {
            if (KeyAlgorithm::ES256 !== $keys->rings()->forRealm($realm)->signer()->algorithm) {
                continue;
            }

            $unanchored = $this->checkpoints->checkpoints()->unanchored($realm, $file->read($realm));

            if (null !== $unanchored) {
                yield Finding::warning(\sprintf('Switching to the FIPS mode will fail verification for the realm "%s": its Ed25519 checkpoints, up to entry %d, have no anchor after them.', $realm->name, $unanchored), \sprintf('Run yorvoid:log:anchor --realm %s.', $realm->name), 'concepts/keys.md#moving-to-es256');
            }
        }
    }

    /**
     * Every ring: the shared one, or each realm's.
     *
     * @return iterable<string, KeyRing>
     */
    private function rings(): iterable
    {
        $keys = $this->checkpoints->keys;

        if (!$keys->isPerRealm()) {
            $file = $keys->path(Realm::default());

            if (null !== $file && file_exists($file)) {
                yield 'the shared key ring' => KeyRing::fromFile($file);
            }

            return;
        }

        $files = glob(rtrim((string) $this->directory, '/').'/*.json');

        foreach (false === $files ? [] : $files as $file) {
            yield \sprintf('the realm "%s"', basename($file, '.json')) => KeyRing::fromFile($file);
        }
    }
}
