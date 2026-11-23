<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Key;

use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Key\KeyRings;
use YorvoID\Core\Realm;

/**
 * The configured key rings, read when a realm's is first asked for rather than when the container is
 * built: a key file that's missing or unreadable then refuses only what needs it.
 *
 * @internal
 */
final readonly class ConfiguredKeyRings implements KeyRings
{
    public function __construct(
        private KeyFiles $files,
    ) {
    }

    public function forRealm(Realm $realm): KeyRing
    {
        return $this->files->rings()->forRealm($realm);
    }
}
