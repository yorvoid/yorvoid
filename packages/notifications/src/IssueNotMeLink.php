<?php

declare(strict_types=1);

namespace YorvoID\Notifications;

use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Token\IssuedToken;
use YorvoID\Core\Duration;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Issues the token of a security notification's "this wasn't me" link: YorvoID's own doing, as the
 * host's operator, and the only way to issue one (M2-13).
 *
 * @implements Command<IssuedToken>
 *
 * @api
 */
#[Operation('yorvoid.not_me.issue', 'Issue the "this wasn\'t me" link of a security notification', AuthorizedBy::Operator, causes: ['yorvoid.token.issue'])]
final readonly class IssueNotMeLink implements Command, ConcernsPrincipal
{
    /**
     * @param CredentialId|null $suspect The sign-in method that "this wasn't me" disables, if any
     * @param string|null       $sentTo  The principal's current email address, which the token is then bound to
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public ?CredentialId $suspect,
        public Duration $lifetime,
        public ?string $sentTo = null,
    ) {
    }

    public function principal(): PrincipalId
    {
        return $this->principalId;
    }
}
