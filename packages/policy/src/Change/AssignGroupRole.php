<?php

declare(strict_types=1);

namespace YorvoID\Policy\Change;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Policy\GroupRoleAssignment;
use YorvoID\Policy\YorvoIDDeclarations;

/**
 * Gives a group a role: every member has it, in the whole realm or on one resource, until a moment if it expires.
 *
 * @implements Command<GroupRoleAssignment>
 *
 * @api
 */
#[Operation('yorvoid.group.assign_role', 'Give a group a role', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: YorvoIDDeclarations::ROLES_MANAGE, inverse: 'yorvoid.group.unassign_role')]
final readonly class AssignGroupRole implements Command
{
    public function __construct(
        public Realm $realm,
        public string $group,
        public string $role,
        public ?ResourceRef $scope = null,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
    }
}
