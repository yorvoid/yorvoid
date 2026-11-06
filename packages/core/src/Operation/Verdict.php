<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

/**
 * @api
 */
enum Verdict: string
{
    case Permit = 'permit';

    case Deny = 'deny';

    /** No policy could decide, or something it needs failed: never permits (P1) */
    case Indeterminate = 'indeterminate';
}
