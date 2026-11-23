<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Realm;

/**
 * The realms' versions of what decisions read, one counter per realm and source, e.g. "policy" and
 * "relations" (D-44): the stores that hold authority bump their source's in the transaction of each
 * change, and a decision reads every source's at once, in one statement. A source never bumped is at 0.
 *
 * @spi
 */
interface VersionStore
{
    /**
     * Each source's version in the realm, at once.
     *
     * @param list<string> $sources
     *
     * @return array<string, int> By source, each one asked; 0 for one never bumped
     */
    public function versions(Realm $realm, array $sources): array;

    /**
     * Increments the source's version, making it 1 if it was never bumped, and holds it until the
     * transaction ends, so changes of one realm's source go one after another.
     *
     * @return int The new version
     */
    public function bump(Realm $realm, string $source): int;
}
