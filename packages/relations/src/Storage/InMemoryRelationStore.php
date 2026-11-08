<?php

declare(strict_types=1);

namespace YorvoID\Relations\Storage;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\RelationTuple;

/**
 * @implements InMemoryStore<array<string, array<string, RelationTuple>>>
 *
 * @api
 */
final class InMemoryRelationStore implements RelationStore, InMemoryStore
{
    /** @var array<string, array<string, RelationTuple>> By realm, then as written */
    private array $tuples = [];

    public function add(RelationTuple $tuple): void
    {
        $this->tuples[$tuple->realm->name][(string) $tuple] = $tuple;
    }

    public function remove(RelationTuple $tuple): bool
    {
        $had = isset($this->tuples[$tuple->realm->name][(string) $tuple]);
        unset($this->tuples[$tuple->realm->name][(string) $tuple]);

        return $had;
    }

    public function subjects(Realm $realm, array $of): array
    {
        $wanted = array_map(static fn (array $pair): string => $pair[0].'#'.$pair[1], $of);

        return self::sorted(array_filter($this->tuples[$realm->name] ?? [], static fn (RelationTuple $tuple): bool => \in_array($tuple->object.'#'.$tuple->relation, $wanted, true)));
    }

    public function objects(Realm $realm, array $subjects, string $relation): array
    {
        $wanted = array_map(strval(...), $subjects);

        return self::sorted(array_filter($this->tuples[$realm->name] ?? [], static fn (RelationTuple $tuple): bool => $tuple->relation === $relation && \in_array((string) $tuple->subject, $wanted, true)));
    }

    public function of(Realm $realm, ResourceRef $object): array
    {
        return self::sorted(array_filter($this->tuples[$realm->name] ?? [], static fn (RelationTuple $tuple): bool => $tuple->object->equals($object)));
    }

    public function forget(Realm $realm, ResourceRef $resource): void
    {
        foreach ($this->tuples[$realm->name] ?? [] as $key => $tuple) {
            if ($tuple->object->equals($resource) || $tuple->subject->resource->equals($resource)) {
                unset($this->tuples[$realm->name][$key]);
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

    /**
     * @param array<string, RelationTuple> $tuples
     *
     * @return list<RelationTuple> As written, e.g. page:1#editor@principal:ada, sorted
     */
    private static function sorted(array $tuples): array
    {
        usort($tuples, static fn (RelationTuple $a, RelationTuple $b): int => (string) $a <=> (string) $b);

        return $tuples;
    }
}
