<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\ResourceRef;

/**
 * Roles and abilities a change gives or takes, on a resource or where the request is about.
 *
 * @internal
 */
final readonly class EffectPart
{
    /**
     * @param list<string>     $roles
     * @param list<string>     $abilities
     * @param ResourceRef|null $on         Where: the request's resource (or the realm) if null
     * @param bool             $byRelation Whether a relation to it holds an ability, or only a role does (for
     *                                     what every descendant gets)
     */
    public function __construct(
        public array $roles = [],
        public array $abilities = [],
        public ?ResourceRef $on = null,
        public bool $byRelation = true,
    ) {
    }
}
