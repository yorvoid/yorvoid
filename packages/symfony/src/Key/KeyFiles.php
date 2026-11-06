<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Key;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Key\KeyRings;
use YorvoID\Core\Key\PerRealmKeyRings;
use YorvoID\Core\Key\SharedKeyRings;
use YorvoID\Core\Realm;

/**
 * Where the bundle's signing keys are, as configured: one key file for every realm (yorvoid.keys.file),
 * or one for each in a folder (yorvoid.keys.per_realm with yorvoid.keys.directory).
 *
 * @internal
 */
final readonly class KeyFiles
{
    public function __construct(
        private ?string $file,
        private bool $perRealm = false,
        private ?string $directory = null,
    ) {
        if ($perRealm && null === $directory) {
            throw new IntegratorError('yorvoid.keys.per_realm needs a folder for the realms\' key files.', 'Set yorvoid.keys.directory, e.g. to "/run/secrets/yorvoid-keys".', 'concepts/keys.md#a-ring-for-each-realm');
        }
    }

    public function isConfigured(): bool
    {
        return $this->perRealm || null !== $this->file;
    }

    public function isPerRealm(): bool
    {
        return $this->perRealm;
    }

    /**
     * The realm's key file, whether or not it exists yet; null when none is configured.
     */
    public function path(Realm $realm): ?string
    {
        return $this->perRealm && null !== $this->directory ? new PerRealmKeyRings($this->directory)->file($realm) : $this->file;
    }

    /**
     * @throws IntegratorError When no key file is configured, or one can't be read
     */
    public function rings(): KeyRings
    {
        if ($this->perRealm && null !== $this->directory) {
            return new PerRealmKeyRings($this->directory);
        }

        if (null === $this->file) {
            throw new IntegratorError('YorvoID has no signing keys configured.', 'Set yorvoid.keys.file (yorvoid:keys:rotate creates it), or yorvoid.keys.per_realm with yorvoid.keys.directory.', 'concepts/keys.md#where-keys-are-kept');
        }

        return new SharedKeyRings(KeyRing::fromFile($this->file));
    }
}
