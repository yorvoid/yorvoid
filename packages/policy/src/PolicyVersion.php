<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\VersionSource;
use YorvoID\Core\Realm;

/**
 * The realm's policy version: roles, abilities, assignments, groups and attributes.
 *
 * @api
 */
final readonly class PolicyVersion implements VersionSource
{
    public function __construct(
        private PolicyStore $store,
    ) {
    }

    public function name(): string
    {
        return 'policy';
    }

    public function version(Realm $realm): int
    {
        return $this->store->version($realm);
    }
}
