<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Ends all of a principal's sessions, or all but one: "sign out everywhere else" (SES-004), or an
 * administrator ending someone's sessions (SES-013).
 *
 * @api
 */
#[Operation('yorvoid.session.end_all', 'End all sessions of a principal', ability: 'yorvoid.sessions.manage')]
final readonly class EndSessions
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
}
