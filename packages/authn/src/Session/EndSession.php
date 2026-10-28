<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * Ends one session (SES-004, SES-013). Anyone may end their own sessions; ending someone else's needs
 * the ability below.
 *
 * @api
 */
#[Operation('yorvoid.session.end', 'End a session', ability: 'yorvoid.sessions.manage')]
final readonly class EndSession
{
    public function __construct(
        public Realm $realm,
        public SessionId $sessionId,
        public SessionEndReason $reason,
    ) {
    }
}
