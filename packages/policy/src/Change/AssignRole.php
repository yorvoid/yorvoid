<?php

declare(strict_types=1);

namespace YorvoID\Policy\Change;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\RoleAssignment;
use YorvoID\Policy\YorvoIDDeclarations;

/**
 * Gives a principal a role: in the whole realm, or on one resource; until a moment, if it expires.
 * Giving it again replaces the expiry.
 *
 * @implements Command<RoleAssignment>
 *
 * @api
 */
#[Operation('yorvoid.role.assign', 'Give someone a role', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: YorvoIDDeclarations::ROLES_MANAGE, inverse: 'yorvoid.role.unassign')]
final readonly class AssignRole implements Command
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public string $role,
        public ?ResourceRef $scope = null,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
    }
}
