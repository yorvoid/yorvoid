<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * A rule that refuses a request when its condition holds, whatever permits it; when its condition is
 * unknown, the decision is Indeterminate (06 §3, rows 1 and 2).
 *
 * @api
 */
interface ForbidElement extends PolicyElement
{
}
