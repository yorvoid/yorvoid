<?php

declare(strict_types=1);

namespace YorvoID\Policy\ViewAs;

use YorvoID\Core\Authorization\ResourceRef;

/**
 * An ability the target of a view-as has and the viewer doesn't, in the whole realm or on a resource:
 * the view refuses it, though the target would be permitted.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's view-as uses it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class Beyond
{
    /**
     * @param ResourceRef|null $on The resource the target has it on; null for the whole realm
     */
    public function __construct(
        public string $ability,
        public ?ResourceRef $on = null,
    ) {
    }
}
