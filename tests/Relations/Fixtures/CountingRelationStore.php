<?php

declare(strict_types=1);

namespace YorvoID\Tests\Relations\Fixtures;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\RelationTuple;

/**
 * A relation store that counts the reads of subjects: one per level of a check.
 */
final class CountingRelationStore implements RelationStore
{
    public int $reads = 0;

    public function __construct(
        private readonly RelationStore $store,
    ) {
    }

    public function add(RelationTuple $tuple): void
    {
        $this->store->add($tuple);
    }

    public function remove(RelationTuple $tuple): bool
    {
        return $this->store->remove($tuple);
    }

    public function subjects(Realm $realm, array $of): array
    {
        ++$this->reads;

        return $this->store->subjects($realm, $of);
    }

    public function objects(Realm $realm, array $subjects, string $relation): array
    {
        return $this->store->objects($realm, $subjects, $relation);
    }

    public function of(Realm $realm, ResourceRef $object): array
    {
        return $this->store->of($realm, $object);
    }

    public function forget(Realm $realm, ResourceRef $resource): void
    {
        $this->store->forget($realm, $resource);
    }

    public function version(Realm $realm): int
    {
        return $this->store->version($realm);
    }
}
