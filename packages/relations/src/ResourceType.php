<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Exception\IntegratorError;

/**
 * A type of resource and its relations, as its owner declares them.
 *
 * @api
 */
final readonly class ResourceType
{
    /** @var array<string, RelationDefinition> By name */
    public array $relations;

    /**
     * @param list<RelationDefinition> $relations
     *
     * @throws IntegratorError When the name isn't a resource type's, or two relations share a name
     */
    public function __construct(
        public string $name,
        array $relations,
    ) {
        new ResourceRef($name, 'x');
        $byName = [];

        foreach ($relations as $relation) {
            if (isset($byName[$relation->name])) {
                throw new IntegratorError(\sprintf('The type "%s" declares the relation "%s" twice.', $name, $relation->name), 'Declare each relation of a type once.', 'concepts/authorization.md#relations');
            }

            $byName[$relation->name] = $relation;
        }

        $this->relations = $byName;
    }
}
