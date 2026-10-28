<?php

declare(strict_types=1);

namespace YorvoID\Authn\Token;

use YorvoID\Core\Duration;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Issues a one-time token for a principal and a purpose, e.g. for a link in an email.
 *
 * @api
 */
#[Operation('yorvoid.token.issue', 'Issue a one-time token', ability: 'yorvoid.principals.manage', inverse: 'yorvoid.token.revoke')]
final readonly class IssueToken
{
    /**
     * @param string      $purpose    What it's for: lowercase letters, digits, dots and underscores, e.g. "acme.password_reset"
     * @param string|null $sentTo     The email address it's sent to, which must be the principal's: the token then
     *                                works only while it still is
     * @param bool        $newestOnly Whether the principal's earlier tokens for the purpose stop working
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public string $purpose,
        public Duration $lifetime,
        public ?string $sentTo = null,
        public bool $newestOnly = true,
    ) {
    }
}
