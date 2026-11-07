<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Authorization\ResourceRef;

/**
 * A command about one resource, e.g. a site: roles assigned on that resource then count for it.
 *
 * @api
 */
interface ConcernsResource
{
    public function resource(): ResourceRef;
}
