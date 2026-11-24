<?php

declare(strict_types=1);

namespace YorvoID\Delegation;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * A delegation that can't be issued or revoked as asked: wider than its parent, of what can't be
 * delegated, past the limits, by an agent, or of someone else's.
 *
 * @api
 *
 * @experimental
 */
final class DelegationRefused extends \RuntimeException implements YorvoIDException
{
    /**
     * @param string $reason A stable code, e.g. "outside_parent", "non_delegable", "agent_delegator"
     */
    public function __construct(
        public readonly string $reason,
        string $why,
    ) {
        parent::__construct($why);
    }
}
