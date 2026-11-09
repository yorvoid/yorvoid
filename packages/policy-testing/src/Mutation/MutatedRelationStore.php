<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting\Mutation;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;
use YorvoID\Policy\GroupRelations;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\RelationTuple;

/**
 * The relation store, as the mutant on changes what's read: no group's members, or no parents. What's
 * written isn't changed.
 *
 * @internal
 */
final readonly class MutatedRelationStore implements RelationStore
{
    public function __construct(
        private RelationStore $inner,
        private Mutants $mutants,
    ) {
    }

    public function subjects(Realm $realm, array $of): array
    {
        return $this->kept($this->inner->subjects($realm, $of));
    }

    public function objects(Realm $realm, array $subjects, string $relation): array
    {
        return $this->kept($this->inner->objects($realm, $subjects, $relation));
    }

    public function of(Realm $realm, ResourceRef $object): array
    {
        return $this->kept($this->inner->of($realm, $object));
    }

    public function add(RelationTuple $tuple): void
    {
        $this->inner->add($tuple);
    }

    public function remove(RelationTuple $tuple): bool
    {
        return $this->inner->remove($tuple);
    }

    public function forget(Realm $realm, ResourceRef $resource): void
    {
        $this->inner->forget($realm, $resource);
    }

    public function version(Realm $realm): int
    {
        return $this->inner->version($realm);
    }

    /**
     * @param list<RelationTuple> $tuples
     *
     * @return list<RelationTuple>
     */
    private function kept(array $tuples): array
    {
        $kept = [];

        foreach ($tuples as $tuple) {
            $group = null !== $tuple->subject->relation || GroupRelations::TYPE === $tuple->object->type;

            if (!($group && $this->mutants->is(MutationOperator::GroupsDontCount)) && !($this->mutants->is(MutationOperator::ParentsDontCount) && $this->mutants->leadsToParent($tuple->relation))) {
                $kept[] = $tuple;
            }
        }

        return $kept;
    }
}
