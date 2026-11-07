<?php

declare(strict_types=1);

namespace YorvoID\Core\Key;

use YorvoID\Core\Realm;

/**
 * Each realm's signing keys (D-40): one ring for every realm by default ({@see SharedKeyRings}), or a
 * ring of its own for each ({@see PerRealmKeyRings}), so that one tenant's rotation or compromise
 * doesn't touch another's, and a tenant that leaves takes its keys. What YorvoID signs names its realm
 * next to the key's ID, and is verified with that realm's ring.
 *
 * @api
 */
interface KeyRings
{
    /**
     * @throws \YorvoID\Core\Exception\IntegratorError When the realm has no keys
     */
    public function forRealm(Realm $realm): KeyRing;
}
