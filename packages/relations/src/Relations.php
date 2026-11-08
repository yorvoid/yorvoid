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
        /** @var list<array{ResourceRef, string}> $frontier */
        $frontier = [[$object, $relation]];
        $seen = [];

        for ($depth = 0; $depth < $this->maxDepth; ++$depth) {
            if ([] === $frontier) {
                return false;
            }

            // The relations implied on the same object need no reading.
            $pairs = [];
            /** @var array<string, list<string>> $parents Tuple key => the parents' relations they lead to */
            $parents = [];

            while ([] !== $frontier) {
                [$current, $name] = array_shift($frontier);
                $key = $current.'#'.$name;
                $definition = $this->schema->relation($current->type, $name);

                if (isset($seen[$key]) || null === $definition) {
                    continue;
                }

                $seen[$key] = $definition;
                $pairs[$key] = [$current, $name];

                foreach ($definition->impliedBy as $implied) {
                    $frontier[] = [$current, $implied];
                }

                foreach ($definition->fromParents as $via => $parentRelation) {
                    $pairs[$current.'#'.$via] = [$current, $via];
                    $parents[$current.'#'.$via][] = $parentRelation;
                }
            }

            foreach ($this->store->subjects($realm, array_values($pairs)) as $tuple) {
                $key = $tuple->object.'#'.$tuple->relation;

                if (isset($seen[$key])) {
                    if ($tuple->subject->equals($subject)) {
                        return true;
                    }

                    if (null !== $tuple->subject->relation) {
                        $frontier[] = [$tuple->subject->resource, $tuple->subject->relation];
                    }
                }

                foreach ($parents[$key] ?? [] as $parentRelation) {
                    $frontier[] = [$tuple->subject->resource, $parentRelation];
                }
            }
        }

        return [] === $frontier ? false : null;
    }

    /**
     * The resource's ancestors through a relation, e.g. a site's server and its datacentre: nearest first.
     *
     * @return list<ResourceRef>|null Null when they go deeper than the bound
     */
    public function ancestors(Realm $realm, ResourceRef $resource, string $via = 'parent'): ?array
    {
        $ancestors = [];
        $seen = [(string) $resource => $resource];
        $frontier = [$resource];

        for ($depth = 0; $depth < $this->maxDepth; ++$depth) {
            $next = [];

            foreach ($this->store->subjects($realm, array_map(static fn (ResourceRef $child): array => [$child, $via], $frontier)) as $tuple) {
                $parent = $tuple->subject->resource;

                if (!isset($seen[(string) $parent])) {
                    $seen[(string) $parent] = $parent;
                    $ancestors[] = $parent;
                    $next[] = $parent;
                }
            }

            if ([] === $next) {
                return $ancestors;
            }

            $frontier = $next;
        }

        return null;
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

    public function schema(): RelationSchema
    {
        return $this->schema;
    }
}
