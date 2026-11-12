<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery;

use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Issues a recovery link for a principal who lost access (ATN-033), e.g. Quire Server's
 * "owner:recover". Run by an operator with access to the server, never from a web page.
 *
 * @implements Command<\YorvoID\Authn\Token\IssuedToken>
 *
 * @api
 */
#[Operation('yorvoid.recovery_link.issue', 'Issue a recovery link', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: 'yorvoid.principals.recover', causes: ['yorvoid.token.issue'])]
final readonly class IssueRecoveryLink implements Command, ConcernsPrincipal
{
    /**
     * @param Duration|null $lifetime How long the link works; 15 minutes if not given
     */
    /**
     * @param Duration|null $lifetime How long it works: 15 minutes if not given, at most 24 hours
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public ?Duration $lifetime = null,
    ) {
        if (null !== $lifetime && $lifetime->isLongerThan(Duration::hours(24))) {
            throw new IntegratorError(\sprintf('A recovery link for %s works too long.', $lifetime), 'Give it at most 24 hours: it takes the account over for whoever has it. 15 minutes is usual.', 'concepts/recovery-links.md');
        }
    }

    public function principal(): PrincipalId
    {
        return $this->principalId;
    }
}
