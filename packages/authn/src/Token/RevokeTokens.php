<?php

declare(strict_types=1);

namespace YorvoID\Authn\Token;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Makes a principal's tokens for a purpose stop working, e.g. when an invitation is withdrawn.
 *
 * @implements Command<int>
 *
 * @api
 */
#[Operation('yorvoid.token.revoke', 'Revoke a principal\'s one-time tokens for a purpose', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: 'yorvoid.principals.manage')]
final readonly class RevokeTokens implements Command, ConcernsPrincipal
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public string $purpose,
    ) {
    }

    public function principal(): PrincipalId
    {
        return $this->principalId;
    }
}
