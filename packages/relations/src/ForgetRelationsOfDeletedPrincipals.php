<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\PrincipalStatus;

/**
 * Removes every relation of a principal that's being deleted, in the deletion's transaction.
 *
 * On the deletion's permit: forgetting is what DeletePrincipal causes (ForgetRelations), and only about the
 * principal it deletes.
 *
 * @api
 */
final readonly class ForgetRelationsOfDeletedPrincipals implements OperationHandler
{
    public function __construct(
        private RelationStore $store,
    ) {
    }

    public function operations(): array
    {
        return [ForgetRelations::class => $this->forget(...)];
    }

    public function __invoke(PrincipalStatusChanging $event): void
    {
        if (PrincipalStatus::Deleted === $event->after->status) {
            $event->permit->cause($this->forget(...), new ForgetRelations($event->after->realm, $event->after->id), $event->origin);
        }
    }

    private function forget(ForgetRelations $command, Permit $permit, Origin $origin): void
    {
        $permit->use($command);
        $this->store->forget($command->realm, Subject::principal($command->principalId)->resource);
    }
}
