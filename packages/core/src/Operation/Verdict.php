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

    /**
     * Permitted while a store was unreachable, by an OfflineDecider: not a Permit. Only an operation that
     * declares acceptsOffline runs on it (P1′).
     */
    case PermitOffline = 'permit_offline';
}
