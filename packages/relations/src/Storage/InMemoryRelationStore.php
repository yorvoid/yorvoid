<?php

declare(strict_types=1);

namespace YorvoID\Relations\Storage;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryStore;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\RelationTuple;

/**
 * @implements InMemoryStore<array{tuples?: array<string, array<string, RelationTuple>>, versions?: array<string, int>}>
 *
 * @api
 */
final class InMemoryRelationStore implements RelationStore, InMemoryStore
{
    /** @var array<string, array<string, RelationTuple>> By realm, then as written */
    private array $tuples = [];

    /** @var array<string, int> By realm */
    private array $versions = [];

    public function add(RelationTuple $tuple): void
    {
        if (!isset($this->tuples[$tuple->realm->name][self::key($tuple)])) {
            $this->tuples[$tuple->realm->name][self::key($tuple)] = $tuple;
            $this->bump($tuple->realm);
        }
    }

    public function remove(RelationTuple $tuple): bool
    {
        if (!isset($this->tuples[$tuple->realm->name][self::key($tuple)])) {
            return false;
        }

        unset($this->tuples[$tuple->realm->name][self::key($tuple)]);
        $this->bump($tuple->realm);

        return true;
    }

    public function subjects(Realm $realm, array $of): array
    {
        return self::sorted(array_filter($this->tuples[$realm->name] ?? [], static fn (RelationTuple $tuple): bool => [] !== array_filter($of, static fn (array $pair): bool => $tuple->object->equals($pair[0]) && $tuple->relation === $pair[1])));
    }

    public function objects(Realm $realm, array $subjects, string $relation): array
    {
        return self::sorted(array_filter($this->tuples[$realm->name] ?? [], static fn (RelationTuple $tuple): bool => $tuple->relation === $relation && [] !== array_filter($subjects, $tuple->subject->equals(...))));
    }

    public function of(Realm $realm, ResourceRef $object): array
    {
        return self::sorted(array_filter($this->tuples[$realm->name] ?? [], static fn (RelationTuple $tuple): bool => $tuple->object->equals($object)));
    }

    public function forget(Realm $realm, ResourceRef $resource): void
    {
        $removed = 0;

        foreach ($this->tuples[$realm->name] ?? [] as $key => $tuple) {
            if ($tuple->object->equals($resource) || $tuple->subject->resource->equals($resource)) {
                unset($this->tuples[$realm->name][$key]);
                ++$removed;
            }
        }

        if (0 < $removed) {
            $this->bump($realm);
        }
    }

    public function version(Realm $realm): int
    {
        return $this->versions[$realm->name] ?? 0;
    }

    public function snapshot(): mixed
    {
        return ['tuples' => $this->tuples, 'versions' => $this->versions];
    }

    public function restore(mixed $snapshot): void
    {
        $this->tuples = $snapshot['tuples'] ?? [];
        $this->versions = $snapshot['versions'] ?? [];
    }

    private function bump(Realm $realm): void
    {
        $this->versions[$realm->name] = ($this->versions[$realm->name] ?? 0) + 1;
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

    /**
     * A tuple, unambiguously: its string can't tell the parts apart when IDs hold "#" or "@".
     */
    private static function key(RelationTuple $tuple): string
    {
        return json_encode([$tuple->object->type, $tuple->object->id, $tuple->relation, $tuple->subject->resource->type, $tuple->subject->resource->id, $tuple->subject->relation], \JSON_THROW_ON_ERROR);
    }
}
