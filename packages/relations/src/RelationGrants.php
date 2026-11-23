<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Authorization\GrantsThroughRelations;

/**
 * The abilities each relation gives, by the schema.
 *
 * @api
 *
 * @experimental
 */
final readonly class RelationGrants implements GrantsThroughRelations
{
    public function __construct(
        private RelationSchema $schema,
    ) {
    }

    public function abilitiesGivenBy(string $type, string $relation): array
    {
        return $this->schema->abilitiesGivenBy($type, $relation);
    }
}
