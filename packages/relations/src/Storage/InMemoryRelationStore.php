<?php

declare(strict_types=1);

namespace YorvoID\Relations\Storage;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\Subject;

/**
 * @implements InMemoryStore<array<string, RelationTuple>>
 *
 * @api
 */
final class InMemoryRelationStore implements RelationStore, InMemoryStore
{
    /** @var array<string, RelationTuple> By realm and tuple */
    private array $tuples = [];

    public function add(RelationTuple $tuple): void
    {
        $this->tuples[self::key($tuple)] = $tuple;
    }

    public function remove(RelationTuple $tuple): bool
    {
        $had = isset($this->tuples[self::key($tuple)]);
        unset($this->tuples[self::key($tuple)]);

        return $had;
    }

    public function subjects(Realm $realm, array $of): array
    {
        $wanted = [];

        foreach ($of as [$object, $relation]) {
            $wanted[$object.'#'.$relation] = true;
        }

        return self::sorted(array_filter($this->tuples, static fn (RelationTuple $tuple): bool => $tuple->realm->equals($realm) && isset($wanted[$tuple->object.'#'.$tuple->relation])));
    }

    public function objects(Realm $realm, array $subjects, string $relation): array
    {
        $wanted = [];

        foreach ($subjects as $subject) {
            $wanted[(string) $subject] = true;
        }

        return self::sorted(array_filter($this->tuples, static fn (RelationTuple $tuple): bool => $tuple->realm->equals($realm) && $tuple->relation === $relation && isset($wanted[(string) $tuple->subject])));
    }

    public function of(Realm $realm, ResourceRef $object): array
    {
        return self::sorted(array_filter($this->tuples, static fn (RelationTuple $tuple): bool => $tuple->realm->equals($realm) && $tuple->object->equals($object)));
    }

    public function forget(Realm $realm, ResourceRef $resource): void
    {
        foreach ($this->tuples as $key => $tuple) {
            if ($tuple->realm->equals($realm) && ($tuple->object->equals($resource) || $tuple->subject->resource->equals($resource))) {
                unset($this->tuples[$key]);
            }
        }
    }

    public function snapshot(): mixed
    {
        return $this->tuples;
    }

    public function restore(mixed $snapshot): void
    {
        $this->tuples = $snapshot;
    }

    private static function key(RelationTuple $tuple): string
    {
        return $tuple->realm->name."\n".$tuple;
    }

    /**
     * @param array<string, RelationTuple> $tuples
     *
     * @return list<RelationTuple> By object, relation, then subject
     */
    private static function sorted(array $tuples): array
    {
        usort($tuples, static fn (RelationTuple $a, RelationTuple $b): int => [(string) $a->object, $a->relation, (string) $a->subject] <=> [(string) $b->object, $b->relation, (string) $b->subject]);

        return $tuples;
    }
}
