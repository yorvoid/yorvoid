<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Principal\PrincipalId;

/**
 * A command about one principal, e.g. issuing them a token: a consequence of an operation may be about
 * the operation's own principal only (M2-13).
 *
 * @api
 */
interface ConcernsPrincipal
{
    public function principal(): PrincipalId;
}
