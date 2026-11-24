<?php

declare(strict_types=1);

namespace YorvoID\Delegation;

use YorvoID\Core\Authorization\KeepsVersionsIn;
use YorvoID\Core\Authorization\VersionSource;
use YorvoID\Core\Authorization\VersionStore;
use YorvoID\Core\Realm;

/**
 * The realm's delegation version: in a decision's read set, so a revocation is seen at the next use.
 *
 * @api
 *
 * @experimental
 */
final readonly class DelegationsVersion implements VersionSource, KeepsVersionsIn
{
    public function __construct(
        private DelegationStore $store,
    ) {
    }

    public function name(): string
    {
        return 'delegation';
    }

    public function version(Realm $realm): int
    {
        return $this->store->version($realm);
    }

    public function versionStore(): ?VersionStore
    {
        return $this->store instanceof KeepsVersionsIn ? $this->store->versionStore() : null;
    }
}
