<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\PrincipalStatus;

/**
 * Takes every role from a principal that's being deleted, in the deletion's transaction: a deleted
 * principal keeps nothing, and its ID can't come back with a role.
 *
 * @api
 */
final readonly class ForgetRolesOfDeletedPrincipals
{
    public function __construct(
        private PolicyStore $store,
    ) {
    }

    public function __invoke(PrincipalStatusChanging $event): void
    {
        if (PrincipalStatus::Deleted !== $event->after->status) {
            return;
        }

        $this->store->bump($event->after->realm);
        $this->store->forgetPrincipal($event->after->realm, $event->after->id);
    }
}
