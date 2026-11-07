<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * A rule of the policy: a permit or a forbid, with a target (which requests it's about) and a condition
 * (whether it applies to this one). Implement PermitElement or ForbidElement.
 *
 * @api
 */
interface PolicyElement
{
    /** Stable, for reasons and the log, e.g. "acme.badges.night_shift" */
    public function name(): string;

    /** Whether the element is about this request at all */
    public function matches(Request $request): bool;

    /**
     * Its condition: holds, fails, or unknown with the reason. An exception counts as unknown
     * (evaluation_error), and StoreUnreachable as unknown (store_unreachable): never as holding.
     */
    public function evaluate(Request $request): Truth;
}
