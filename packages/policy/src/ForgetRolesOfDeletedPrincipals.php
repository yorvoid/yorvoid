<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\PrincipalStatus;

/**
 * Takes every role from a principal that's being deleted, in the deletion's transaction: a deleted
 * principal keeps nothing, and its ID can't come back with a role.
 *
 * On the deletion's permit: forgetting is what DeletePrincipal causes (ForgetRoles), and only about the
 * principal it deletes.
 *
 * @api
 */
final readonly class ForgetRolesOfDeletedPrincipals implements OperationHandler
{
    public function __construct(
        private PolicyStore $store,
    ) {
    }

    public function operations(): array
    {
        return [ForgetRoles::class => $this->forget(...)];
    }

    public function __invoke(PrincipalStatusChanging $event): void
    {
        if (PrincipalStatus::Deleted === $event->after->status) {
            $event->permit->cause($this->forget(...), new ForgetRoles($event->after->realm, $event->after->id), $event->origin);
        }
    }

    private function forget(ForgetRoles $command, Permit $permit, Origin $origin): void
    {
        $permit->use($command);
        $this->store->forgetPrincipal($command->realm, $command->principalId);
    }
}
