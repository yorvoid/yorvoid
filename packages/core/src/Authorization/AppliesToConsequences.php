<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * A forbid that applies to an operation run as a consequence too, not only when it's asked for: a
 * consequence is otherwise decided by its operation's decision, and the forbids aren't evaluated for it
 * again (D-50). Only a ForbidElement can be one.
 *
 * @api
 */
interface AppliesToConsequences extends ForbidElement
{
}
