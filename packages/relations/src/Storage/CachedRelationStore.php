<?php

declare(strict_types=1);

namespace YorvoID\Relations\Storage;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\VersionedCache;
use YorvoID\Relations\RelationStore;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\Subject;

/**
 * A relation store whose reads are kept against the realm's relations version (AUZ-031): read once in
 * a request, and from a shared pool if the cache has one. The version is read once in a request too,
 * so a request decides by one state of the relations. Once the request changes a realm's relations,
 * it reads them from the store until reset.
 *
 * @api
 */
final class CachedRelationStore implements RelationStore
{
    /** @var array<string, int> By realm */
    private array $versions = [];

    /** @var array<string, true> The realms this request changed */
    private array $changed = [];

    public function __construct(
        private readonly RelationStore $store,
        private readonly VersionedCache $cache,
    ) {
    }

    public function add(RelationTuple $tuple): void
    {
        $this->changed[$tuple->realm->name] = true;
        $this->store->add($tuple);
    }

    public function remove(RelationTuple $tuple): bool
    {
        $this->changed[$tuple->realm->name] = true;

        return $this->store->remove($tuple);
    }

    public function subjects(Realm $realm, array $of): array
    {
        $pairs = array_merge(...array_map(static fn (array $pair): array => [(string) $pair[0], $pair[1]], $of));

        return $this->read($realm, 'subjects', $pairs, fn (): array => $this->store->subjects($realm, $of), static fn (RelationTuple $tuple): bool => [] !== array_filter($of, static fn (array $pair): bool => $tuple->object->equals($pair[0]) && $tuple->relation === $pair[1]));
    }

    public function objects(Realm $realm, array $subjects, string $relation): array
    {
        // Each subject as three parts: its string alone can't tell group:a#member from group:a with the relation member.
        $arguments = [$relation, ...array_merge(...array_map(static fn (Subject $subject): array => [$subject->resource->type, $subject->resource->id, $subject->relation ?? ''], $subjects))];

        return $this->read($realm, 'objects', $arguments, fn (): array => $this->store->objects($realm, $subjects, $relation), static fn (RelationTuple $tuple): bool => $tuple->relation === $relation && [] !== array_filter($subjects, $tuple->subject->equals(...)));
    }

    public function of(Realm $realm, ResourceRef $object): array
    {
        return $this->read($realm, 'of', [(string) $object], fn (): array => $this->store->of($realm, $object), static fn (RelationTuple $tuple): bool => $tuple->object->equals($object));
    }

    public function forget(Realm $realm, ResourceRef $resource): void
    {
        $this->changed[$realm->name] = true;
        $this->store->forget($realm, $resource);
    }

    public function version(Realm $realm): int
    {
        if ($this->changed[$realm->name] ?? false) {
            return $this->store->version($realm);
        }

        return $this->versions[$realm->name] ??= $this->store->version($realm);
    }

    /**
     * Forgets the versions read and the changes made: between requests, and between a worker's messages.
     */
    public function reset(): void
    {
        $this->versions = [];
        $this->changed = [];
    }

    /**
     * @param list<string>                    $of      The read's arguments
     * @param \Closure(): list<RelationTuple> $load
     * @param \Closure(RelationTuple): bool   $answers Whether a tuple answers the read: a pool's entry for another read is read again
     *
     * @return list<RelationTuple>
     */
    private function read(Realm $realm, string $read, array $of, \Closure $load, \Closure $answers): array
    {
        if ($this->changed[$realm->name] ?? false) {
            return $load();
        }

        // The version first: what's read after it is at least as new as it, never older.
        return $this->cache->get($realm, 'relations', $this->version($realm), $read, $of, $load, static fn (mixed $tuples): bool => \is_array($tuples) && array_is_list($tuples)
            && [] === array_filter($tuples, static fn (mixed $tuple): bool => !$tuple instanceof RelationTuple || !$tuple->realm->equals($realm) || !$answers($tuple)));
    }
}
