<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Realm;

/**
 * Whether a subject has a relation to an object (AUZ-011): directly, through a relation implying it,
 * through a subject set (members of a group), or inherited from a parent. Read a level at a time, one
 * store call per level, and bounded (D-08): deeper than the bound, it can't tell.
 *
 * @api
 */
final readonly class Relations
{
    public const int MAX_DEPTH = 16;

    public function __construct(
        private RelationSchema $schema,
        private RelationStore $store,
        private int $maxDepth = self::MAX_DEPTH,
    ) {
    }

    /**
     * @return bool|null Null when it can't tell within the bound
     */
    public function check(Realm $realm, ResourceRef $object, string $relation, Subject $subject): ?bool
    {
        return $this->checkMany($realm, [$object], $relation, $subject)[(string) $object];
    }

    /**
     * Whether the subject has the relation to each of the objects: as check() for each, with one
     * store call per level for all of them (AUZ-032).
     *
     * @param list<ResourceRef> $objects
     *
     * @return array<string, bool|null> By object, as "type:id"
     */
    public function checkMany(Realm $realm, array $objects, string $relation, Subject $subject): array
    {
        /** @var array<string, list<array{ResourceRef, string}>> $frontiers By object */
        $frontiers = [];
        /** @var array<string, array<string, true>> $seen By object: the object#relation pairs it has reached */
        $seen = [];
        $results = [];

        foreach ($objects as $object) {
            $frontiers[(string) $object] = [[$object, $relation]];
            $seen[(string) $object] = [];
        }

        for ($depth = 0; $depth < $this->maxDepth && [] !== $frontiers; ++$depth) {
            $asked = [];
            /** @var array<string, array{array<string, array{ResourceRef, string}>, array<string, list<string>>}> $levels */
            $levels = [];

            foreach ($frontiers as $key => $frontier) {
                if ([] === $frontier) {
                    $results[$key] = false;
                    unset($frontiers[$key]);

                    continue;
                }

                $levels[$key] = $this->level($frontier, $seen[$key]);
                $asked += $levels[$key][0];
            }

            /** @var array<string, list<RelationTuple>> $tuples By object#relation */
            $tuples = [];

            foreach ([] === $asked ? [] : $this->store->subjects($realm, array_values($asked)) as $tuple) {
                $tuples[$tuple->object.'#'.$tuple->relation][] = $tuple;
            }

            foreach ($levels as $key => [$pairs, $parents]) {
                $next = [];

                foreach (array_keys($pairs) as $pair) {
                    foreach ($tuples[$pair] ?? [] as $tuple) {
                        if ($seen[$key][$pair] ?? false) {
                            if ($tuple->subject->equals($subject)) {
                                $results[$key] = true;
                                unset($frontiers[$key]);

                                continue 3;
                            }

                            if (null !== $tuple->subject->relation) {
                                $next[] = [$tuple->subject->resource, $tuple->subject->relation];
                            }
                        }

                        foreach ($parents[$pair] ?? [] as $parentRelation) {
                            $next[] = [$tuple->subject->resource, $parentRelation];
                        }
                    }
                }

                $frontiers[$key] = $next;
            }
        }

        foreach ($frontiers as $key => $frontier) {
            $results[$key] = [] === $frontier ? false : null;
        }

        return $results;
    }

    /**
     * The resource's ancestors through a relation, e.g. a site's server and its datacentre: nearest first.
     *
     * @return list<ResourceRef>|null Null when they go deeper than the bound
     */
    public function ancestors(Realm $realm, ResourceRef $resource, string $via = 'parent'): ?array
    {
        return $this->ancestorsOfMany($realm, [$resource], $via)[(string) $resource];
    }

    /**
     * The ancestors of each of the resources: as ancestors() for each, with one store call per level
     * for all of them.
     *
     * @param list<ResourceRef> $resources
     *
     * @return array<string, list<ResourceRef>|null> By resource, as "type:id"
     */
    public function ancestorsOfMany(Realm $realm, array $resources, string $via = 'parent'): array
    {
        /** @var array<string, list<ResourceRef>> $frontiers */
        $frontiers = [];
        /** @var array<string, list<ResourceRef>> $found */
        $found = [];
        /** @var array<string, array<string, true>> $seen */
        $seen = [];
        $results = [];

        foreach ($resources as $resource) {
            $frontiers[(string) $resource] = [$resource];
            $found[(string) $resource] = [];
            $seen[(string) $resource] = [(string) $resource => true];
        }

        for ($depth = 0; $depth < $this->maxDepth && [] !== $frontiers; ++$depth) {
            $children = [];

            foreach ($frontiers as $frontier) {
                foreach ($frontier as $child) {
                    $children[(string) $child] = [$child, $via];
                }
            }

            /** @var array<string, list<ResourceRef>> $parents By child */
            $parents = [];

            foreach ($this->store->subjects($realm, array_values($children)) as $tuple) {
                $parents[(string) $tuple->object][] = $tuple->subject->resource;
            }

            foreach ($frontiers as $key => $frontier) {
                $next = [];

                foreach ($frontier as $child) {
                    foreach ($parents[(string) $child] ?? [] as $parent) {
                        if (!($seen[$key][(string) $parent] ?? false)) {
                            $seen[$key][(string) $parent] = true;
                            $found[$key][] = $parent;
                            $next[] = $parent;
                        }
                    }
                }

                if ([] === $next) {
                    $results[$key] = $found[$key];
                    unset($frontiers[$key]);
                } else {
                    $frontiers[$key] = $next;
                }
            }
        }

        foreach (array_keys($frontiers) as $key) {
            $results[$key] = null;
        }

        return $results;
    }

    /**
     * The resources of a type the subject has the relation to, directly or through those it has it to,
     * e.g. every group a principal is a member of, nested groups included.
     *
     * @return list<ResourceRef>|null Null when they go deeper than the bound
     */
    public function memberships(Realm $realm, Subject $subject, string $type, string $relation): ?array
    {
        $found = [];
        $frontier = [$subject];

        for ($depth = 0; $depth < $this->maxDepth; ++$depth) {
            $next = [];

            foreach ($this->store->objects($realm, $frontier, $relation) as $tuple) {
                if ($tuple->object->type === $type && !isset($found[(string) $tuple->object])) {
                    $found[(string) $tuple->object] = $tuple->object;
                    $next[] = new Subject($tuple->object, $relation);
                }
            }

            if ([] === $next) {
                return array_values($found);
            }

            $frontier = $next;
        }

        return null;
    }

    /**
     * One level of a check: the pairs to read, with the relations implied on the same object, which
     * need no reading, and the parents' relations that each pair leads to.
     *
     * @param list<array{ResourceRef, string}> $frontier
     * @param array<string, true>              $seen     The pairs reached, this level's added
     *
     * @return array{array<string, array{ResourceRef, string}>, array<string, list<string>>}
     */
    private function level(array $frontier, array &$seen): array
    {
        $pairs = [];
        $parents = [];

        while ([] !== $frontier) {
            [$current, $name] = array_shift($frontier);
            $key = $current.'#'.$name;
            $definition = $this->schema->relation($current->type, $name);

            if (($seen[$key] ?? false) || null === $definition) {
                continue;
            }

            $seen[$key] = true;
            $pairs[$key] = [$current, $name];

            foreach ($definition->impliedBy as $implied) {
                $frontier[] = [$current, $implied];
            }

            foreach ($definition->fromParents as $via => $parentRelation) {
                $pairs[$current.'#'.$via] = [$current, $via];
                $parents[$current.'#'.$via][] = $parentRelation;
            }
        }

        return [$pairs, $parents];
    }

    public function schema(): RelationSchema
    {
        return $this->schema;
    }
}
