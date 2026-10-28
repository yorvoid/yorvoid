<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery;

use YorvoID\Core\Duration;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Issues a recovery link for a principal who lost access (ATN-033), e.g. Quire Server's
 * "owner:recover". Run by an operator with access to the server, never from a web page.
 *
 * @api
 */
#[Operation('yorvoid.recovery_link.issue', 'Issue a recovery link', ability: 'yorvoid.principals.recover')]
final readonly class IssueRecoveryLink
{
    /**
     * @param Duration|null $lifetime How long the link works; 15 minutes if not given
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public ?Duration $lifetime = null,
    ) {
    }
}
