<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\SelfOperation;
use YorvoID\Core\Realm;

/**
 * Ends one session (SES-004, SES-013). Anyone may end their own sessions; ending someone else's needs
 * the ability below. Sessions::end() doesn't check either: the caller does, with a recent proof.
 *
 * @implements SelfOperation<bool>
 *
 * @api
 */
#[Operation('yorvoid.session.end', 'End a session', [AuthorizedBy::Self, AuthorizedBy::Ability, AuthorizedBy::Operator], ability: 'yorvoid.sessions.manage')]
final readonly class EndSession implements SelfOperation
{
    public function __construct(
        public Realm $realm,
        public SessionId $sessionId,
        public SessionEndReason $reason,
    ) {
    }

    public function concerns(Actor $actor): bool
    {
        return $actor instanceof Session && $actor->id->equals($this->sessionId);
    }
}
