<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Authorization\VersionSource;
use YorvoID\Core\Realm;

/**
 * The realm's relations version.
 *
 * @api
 */
final readonly class RelationsVersion implements VersionSource
{
    public function __construct(
        private RelationStore $store,
    ) {
    }

    public function name(): string
    {
        return 'relations';
    }

    public function version(Realm $realm): int
    {
        return $this->store->version($realm);
    }
}
