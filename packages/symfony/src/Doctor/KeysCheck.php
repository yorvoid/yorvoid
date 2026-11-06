<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Realm;
use YorvoID\Symfony\Key\KeyFiles;

/**
 * The signing keys: the realm's key file exists, only its owner can read it, and it holds a valid ring.
 * Without one there's nothing to check: nothing YorvoID does yet needs it, until signed checkpoints
 * (AUD-003). With keys per realm, a realm without a file of its own is a problem.
 *
 * @internal
 */
final readonly class KeysCheck implements Check
{
    public function __construct(
        private KeyFiles $keys,
        private Realm $realm,
    ) {
    }

    public function findings(): iterable
    {
        $file = $this->keys->path($this->realm);

        if (null === $file) {
            return;
        }

        if (!file_exists($file)) {
            yield $this->keys->isPerRealm()
                ? Finding::problem(\sprintf('The realm "%s" has no keys: "%s" doesn\'t exist.', $this->realm->name, $file), \sprintf('Create them with yorvoid:keys:rotate --realm %s.', $this->realm->name), 'concepts/keys.md#a-ring-for-each-realm')
                : Finding::problem(\sprintf('The key file "%s" doesn\'t exist.', $file), 'Create it with yorvoid:keys:rotate.', 'concepts/keys.md#rotating-keys');

            return;
        }

        // Throws what's wrong with it, which the doctor reports.
        KeyRing::fromFile($file);
    }
}
