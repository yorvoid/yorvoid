<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;

/**
 * How long sessions last (SES-003): at most $absolute after signing in, and at most $idle after the last
 * request. Tightening the policy applies to sessions that already exist, at their next request.
 *
 * @api
 */
final readonly class SessionPolicy
{
    public function __construct(
        public Duration $absolute,
        public Duration $idle,
    ) {
        if ($idle->isLongerThan($absolute)) {
            throw new IntegratorError(\sprintf('The idle timeout (%s) is longer than the session\'s whole lifetime (%s).', $idle, $absolute), 'Make the idle timeout at most as long as the absolute one, e.g. 8h and 1h.', 'concepts/sessions.md#how-long-sessions-last');
        }
    }

    /**
     * NIST SP 800-63B-4's limits for AAL2: re-authenticate at least every 24 hours, and after an hour
     * without activity.
     */
    public static function aal2(): self
    {
        return new self(Duration::hours(24), Duration::hours(1));
    }

    /**
     * NIST SP 800-63B-4's limits for AAL3: re-authenticate at least every 12 hours, and after 15 minutes
     * without activity.
     */
    public static function aal3(): self
    {
        return new self(Duration::hours(12), Duration::minutes(15));
    }
}
