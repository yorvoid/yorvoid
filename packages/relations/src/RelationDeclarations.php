<?php

declare(strict_types=1);

namespace YorvoID\Relations;

/**
 * The resource types and relations an owner declares. In Symfony, a service implementing it is
 * collected by itself.
 *
 * @api
 */
interface RelationDeclarations
{
    /**
     * @return iterable<ResourceType>
     */
    public function types(): iterable;
}
