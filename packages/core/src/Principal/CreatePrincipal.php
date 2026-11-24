<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * @implements Command<Principal>
 *
 * @api
 */
#[Operation('yorvoid.principal.create', 'Create a principal', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: 'yorvoid.principals.manage', inverse: 'yorvoid.principal.delete', causes: ['yorvoid.token.issue'])]
final readonly class CreatePrincipal implements Command
{
    /**
     * @param bool              $emailVerified Whether the person has shown the email address is theirs; see SetIdentifier
     * @param PrincipalId|null  $owner         An agent's owner, which it needs: an active principal of the realm that isn't an agent (AGT-001)
     * @param AgentProfile|null $profile       An agent's description (experimental)
     */
    public function __construct(
        public Realm $realm,
        public PrincipalKind $kind,
        public PrincipalStatus $status = PrincipalStatus::Active,
        public ?string $email = null,
        public ?string $username = null,
        public bool $emailVerified = false,
        public ?PrincipalId $owner = null,
        public ?AgentProfile $profile = null,
    ) {
    }
}
