<?php

declare(strict_types=1);

namespace YorvoID\Authn\Attempt;

use YorvoID\Core\Duration;
use YorvoID\Throttle\Limit;

/**
 * How often someone may try a form that checks a secret (ATN-040, ATN-041). Each limit slows attempts
 * down once reached rather than locking anyone out: after its attempts, one more is allowed per share of
 * the period.
 *
 * @api
 */
final readonly class AttemptLimits
{
    public Limit $perAccountAndAddress;

    public Limit $perAccount;

    public Limit $perAddress;

    /**
     * @param Limit|null $perAccountAndAddress One account from one address: 10 per 15 minutes if not given
     * @param Limit|null $perAccount           One account from anywhere: 100 per hour if not given, so an attack from many
     *                                         addresses slows down without shutting out the owner for long
     * @param Limit|null $perAddress           One address, whichever accounts: 300 per 15 minutes if not given, leaving room
     *                                         for an office behind one address
     */
    public function __construct(?Limit $perAccountAndAddress = null, ?Limit $perAccount = null, ?Limit $perAddress = null)
    {
        $this->perAccountAndAddress = $perAccountAndAddress ?? new Limit(10, Duration::minutes(15));
        $this->perAccount = $perAccount ?? new Limit(100, Duration::hours(1));
        $this->perAddress = $perAddress ?? new Limit(300, Duration::minutes(15));
    }
}
