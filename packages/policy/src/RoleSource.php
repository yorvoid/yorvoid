<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\Request;

/**
 * Roles an actor has for one request without their being stored, e.g. mapped from the host
 * application's own roles on every request. They add to the assigned ones; names that aren't roles
 * count for nothing.
 *
 * @api
 */
interface RoleSource
{
    /**
     * @return iterable<string>
     */
    public function roles(Request $request): iterable;
}
