<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * Decides while a store can't be reached (XP-03), e.g. on a node whose link home is down. Asked only
 * when a decision is Indeterminate and every reason is store_unreachable, for an operation that declares
 * acceptsOffline, and only where a permit could have applied. Holding gives Permit-offline, which isn't
 * a Permit (P1′): only such an operation runs on it, and its permit says it was offline. None is
 * registered by default.
 *
 * @api
 */
interface OfflineDecider
{
    /**
     * Whether the request may go ahead offline: holds, or fails (or unknown) with the reason.
     */
    public function decide(Request $request): Truth;
}
