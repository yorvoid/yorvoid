<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * An exception a policy element throws when something its condition needs isn't on record, e.g. a
 * principal's department: the decision is Indeterminate with the reason missing_data, never a Permit
 * (AUZ-007).
 *
 * @api
 */
interface MissingData extends YorvoIDException
{
}
