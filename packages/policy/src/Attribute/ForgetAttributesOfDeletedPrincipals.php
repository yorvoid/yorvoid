<?php

declare(strict_types=1);

namespace YorvoID\Policy\Attribute;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\PrincipalStatus;

/**
 * Removes a principal's security attributes when it's being deleted, in the deletion's transaction.
 *
 * On the deletion's permit: forgetting is what DeletePrincipal causes (ForgetAttributes), and only about the
 * principal it deletes.
 *
 * @api
 */
final readonly class ForgetAttributesOfDeletedPrincipals implements OperationHandler
{
    public function __construct(
        private AttributeStore $store,
    ) {
    }

    public function operations(): array
    {
        return [ForgetAttributes::class => $this->forget(...)];
    }

    public function __invoke(PrincipalStatusChanging $event): void
    {
        if (PrincipalStatus::Deleted === $event->after->status) {
            $event->permit->cause($this->forget(...), new ForgetAttributes($event->after->realm, $event->after->id), $event->origin);
        }
    }

    private function forget(ForgetAttributes $command, Permit $permit, Origin $origin): void
    {
        $permit->use($command);
        $this->store->forget($command->realm, $command->principalId);
    }
}
