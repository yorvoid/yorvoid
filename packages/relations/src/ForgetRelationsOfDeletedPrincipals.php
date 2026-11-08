<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\PrincipalStatus;

/**
 * Removes every relation of a principal that's being deleted, in the deletion's transaction.
 *
 * @api
 */
final readonly class ForgetRelationsOfDeletedPrincipals
{
    public function __construct(
        private RelationStore $store,
    ) {
    }

    public function __invoke(PrincipalStatusChanging $event): void
    {
        if (PrincipalStatus::Deleted === $event->after->status) {
            $this->store->forget($event->after->realm, Subject::principal($event->after->id)->resource);
        }
    }
}
