<?php

declare(strict_types=1);

namespace YorvoID\Core\Key;

use YorvoID\Core\Realm;

/**
 * One ring for every realm: an application with one realm, or realms that trust each other.
 *
 * @api
 */
final readonly class SharedKeyRings implements KeyRings
{
    public function __construct(
        private KeyRing $ring,
    ) {
    }

    public function forRealm(Realm $realm): KeyRing
    {
        return $this->ring;
    }
}
