<?php

declare(strict_types=1);

namespace YorvoID\Authn\Token;

use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Makes a principal's tokens for a purpose stop working, e.g. when an invitation is withdrawn.
 *
 * @api
 */
#[Operation('yorvoid.token.revoke', 'Revoke a principal\'s one-time tokens for a purpose', ability: 'yorvoid.principals.manage')]
final readonly class RevokeTokens
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public string $purpose,
    ) {
    }
}
