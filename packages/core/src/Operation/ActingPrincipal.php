<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A principal acting without a session: e.g. the one an invitation or a recovery just authenticated,
 * for what that causes.
 *
 * @api
 */
final readonly class ActingPrincipal implements Actor
{
    public function __construct(
        public ?Realm $realm,
        public ?PrincipalId $principalId,
    ) {
    }
}
