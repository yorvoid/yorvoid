<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A principal acting without a session: the one an invitation, a recovery or a setup code just
 * authenticated, for what that causes, and only there (D-45, L-4). Permit::cause(…, actingAs:) makes it;
 * Operations::run() refuses it. In a decision of your own, e.g. a policy test, it answers "would this
 * principal be permitted", and proves nothing.
 *
 * @api
 */
final readonly class ActingPrincipal implements Actor
{
    /**
     * @internal YorvoID makes it (Permit::cause()); for a decision of your own, policy tests and views use it too
     */
    public function __construct(
        public ?Realm $realm,
        public ?PrincipalId $principalId,
    ) {
    }
}
