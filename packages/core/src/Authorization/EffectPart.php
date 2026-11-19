<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * Roles and abilities a change gives or takes, on a resource or where the request is about.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise while delegation (M3) shapes it: it may change in a minor
 *               release ([compatibility](docs/compatibility.md)).
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
