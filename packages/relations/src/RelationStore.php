<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;

/**
 * A realm's relations as data, read a level at a time: the check asks for every pair of one level in
 * one call. Every change that changes something increments the realm's version, in the same
 * transaction, so what's cached against it is never used once stale (AUZ-031, D-10).
 *
 * @api
 */
interface RelationStore
{
    /** Adds it; adding it again changes nothing */
    public function add(RelationTuple $tuple): void;

    /**
     * @return bool Whether there was one
     */
    public function remove(RelationTuple $tuple): bool;

    /**
     * The tuples of these objects with these relations.
     *
     * @param list<array{ResourceRef, string}> $of Object and relation
     *
     * @return list<RelationTuple>
     */
    public function subjects(Realm $realm, array $of): array;

    /**
     * The tuples with this relation to these subjects.
     *
     * @param list<Subject> $subjects
     *
     * @return list<RelationTuple>
     */
    public function objects(Realm $realm, array $subjects, string $relation): array;

    /**
     * Every tuple about the object.
     *
     * @return list<RelationTuple> By relation, then subject
     */
    public function of(Realm $realm, ResourceRef $object): array;

    /** Removes every tuple of the resource, as object or as subject: it's gone */
    public function forget(Realm $realm, ResourceRef $resource): void;

    /**
     * 0 before the first change; incremented by every add, remove and forget that changes something.
     * Read from what the store holds, so every process reads the same one: never a counter of the
     * process's own.
     */
    public function version(Realm $realm): int;
}
