<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * Which abilities a relation gives on a resource type, by its definition: what someone holds on every
 * resource they have that relation to. A delegation of a relation's abilities on "the pages I'm an editor
 * of" is held by that definition. relations implements it.
 *
 * @spi
 *
 * @experimental
 */
interface GrantsThroughRelations
{
    /**
     * @return list<string>
     */
    public function abilitiesGivenBy(string $type, string $relation): array;
}
