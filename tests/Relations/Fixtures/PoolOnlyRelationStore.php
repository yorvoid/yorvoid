<?php

declare(strict_types=1);

namespace YorvoID\Tests\Relations\Fixtures;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\RelationTuple;

/**
 * Another server, whose request read the version before a change: every read must come from the pool.
 */
final readonly class PoolOnlyRelationStore implements RelationStore
{
    public function __construct(
        private int $version,
    ) {
    }

    public function add(RelationTuple $tuple): void
    {
        throw new \LogicException('Only read.');
    }

    public function remove(RelationTuple $tuple): bool
    {
        throw new \LogicException('Only read.');
    }

    public function subjects(Realm $realm, array $of): array
    {
        throw new \LogicException('Read from the pool.');
    }

    public function objects(Realm $realm, array $subjects, string $relation): array
    {
        throw new \LogicException('Read from the pool.');
    }

    public function of(Realm $realm, ResourceRef $object): array
    {
        throw new \LogicException('Read from the pool.');
    }

    public function forget(Realm $realm, ResourceRef $resource): void
    {
        throw new \LogicException('Only read.');
    }

    public function version(Realm $realm): int
    {
        return $this->version;
    }
}
