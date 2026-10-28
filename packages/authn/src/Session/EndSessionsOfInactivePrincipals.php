<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use YorvoID\Core\Principal\Event\PrincipalStatusChanging;

/**
 * A listener for PrincipalStatusChanging: when a principal is suspended or deleted, its sessions end in
 * the same transaction, so "your sessions" and administrators' lists are right at once. (Without it,
 * those sessions are refused anyway: a session is valid only while its principal stays active.).
 *
 * @api
 */
final readonly class EndSessionsOfInactivePrincipals
{
    public function __construct(
        private Sessions $sessions,
    ) {
    }

    public function __invoke(PrincipalStatusChanging $event): void
    {
        if (!$event->after->isActive()) {
            $this->sessions->endAll(new EndSessions($event->after->realm, $event->after->id, SessionEndReason::PrincipalDeactivated), $event->origin, $event->actor);
        }
    }
}
