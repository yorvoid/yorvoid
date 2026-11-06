<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\SelfOperation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Ends all of a principal's sessions, or all but one: "sign out everywhere else" (SES-004), or an
 * administrator ending someone's sessions (SES-013).
 *
 * @implements SelfOperation<int>
 *
 * @api
 */
#[Operation('yorvoid.session.end_all', 'End all sessions of a principal', [AuthorizedBy::Self, AuthorizedBy::Ability, AuthorizedBy::Operator], ability: 'yorvoid.sessions.manage')]
final readonly class EndSessions implements SelfOperation
{
    /**
     * @param SessionId|null $except The session to keep, e.g. the one asking
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public SessionEndReason $reason,
        public ?SessionId $except = null,
    ) {
    }

    public function concerns(Actor $actor): bool
    {
        return null !== $actor->principalId && $actor->principalId->equals($this->principalId);
    }
}
