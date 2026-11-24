<?php

declare(strict_types=1);

namespace YorvoID\Delegation;

use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\PrincipalKind;

/**
 * How far a delegation may go when it's issued (AGT-043): how long, how deep a chain, how many actions
 * and IDs. An agent's are tighter by default.
 *
 * @api
 *
 * @experimental
 */
final readonly class DelegationLimits
{
    public Duration $longest;

    public Duration $longestAgent;

    /**
     * @param Duration|null $longest      The longest a delegation to anyone but an agent lasts: a year if not given
     * @param Duration|null $longestAgent The longest a delegation to an agent lasts: 30 days if not given
     * @param int           $depth        How long a chain may be, the root's delegation first
     * @param int           $actions      How many actions one names, once expanded
     * @param int           $ids          How many IDs its selector names
     */
    public function __construct(
        ?Duration $longest = null,
        ?Duration $longestAgent = null,
        public int $depth = 3,
        public int $actions = 100,
        public int $ids = 100,
    ) {
        $this->longest = $longest ?? Duration::days(365);
        $this->longestAgent = $longestAgent ?? Duration::days(30);

        if ($depth < 1 || $actions < 1 || $ids < 1) {
            throw new IntegratorError('A delegation\'s limits are at least 1.', 'Give a chain\'s depth, the actions and the IDs one may name as positive numbers.', 'concepts/delegation.md#limits');
        }
    }

    public function longestFor(PrincipalKind $delegate): Duration
    {
        return PrincipalKind::Agent === $delegate ? $this->longestAgent : $this->longest;
    }
}
