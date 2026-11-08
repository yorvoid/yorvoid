<?php

declare(strict_types=1);

namespace YorvoID\Policy\Attribute;

use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\PrincipalStatus;

/**
 * Removes a principal's security attributes when it's being deleted, in the deletion's transaction.
 *
 * @api
 */
final readonly class ForgetAttributesOfDeletedPrincipals
{
    public function __construct(
        private AttributeStore $store,
    ) {
    }

    public function __invoke(PrincipalStatusChanging $event): void
    {
        if (PrincipalStatus::Deleted === $event->after->status) {
            $this->store->forget($event->after->realm, $event->after->id);
        }
    }
}
