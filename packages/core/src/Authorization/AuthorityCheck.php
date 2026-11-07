<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * Whether the actor's authority is valid at the moment of the request: holds (valid), fails (invalid,
 * e.g. a suspended principal) or unknown (06 §3, rows 3 and 4). Delegated authority (M3) comes in
 * through this check.
 *
 * @api
 */
interface AuthorityCheck
{
    public function check(Request $request): Truth;
}
