<?php

declare(strict_types=1);

namespace YorvoID\Core\Key;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Realm;

/**
 * A ring of its own for each realm, in a folder: "acme.json" for the realm "acme", each a key file only
 * its owner can read. A realm's key IDs are its own; what one realm signed doesn't verify with another's
 * keys.
 *
 * @api
 */
final readonly class PerRealmKeyRings implements KeyRings
{
    public function __construct(
        private string $directory,
    ) {
    }

    /**
     * Where the realm's keys are, whether or not they exist yet.
     */
    public function file(Realm $realm): string
    {
        // Realm names are lowercase letters, digits and hyphens: a file name as they are.
        return rtrim($this->directory, '/').'/'.$realm->name.'.json';
    }

    public function has(Realm $realm): bool
    {
        return is_file($this->file($realm));
    }

    public function forRealm(Realm $realm): KeyRing
    {
        if (!$this->has($realm)) {
            throw new IntegratorError(\sprintf('The realm "%s" has no keys: "%s" doesn\'t exist.', $realm->name, $this->file($realm)), \sprintf('Create them with yorvoid:keys:rotate --realm %s, or KeyRing::toFile().', $realm->name), 'concepts/keys.md#a-ring-for-each-realm');
        }

        return KeyRing::fromFile($this->file($realm));
    }
}
