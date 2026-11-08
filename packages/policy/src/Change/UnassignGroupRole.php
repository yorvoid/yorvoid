<?php

declare(strict_types=1);

namespace YorvoID\Policy\Change;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Policy\YorvoIDDeclarations;

/**
 * Takes a role from a group.
 *
 * @implements Command<bool> Whether it had it
 *
 * @api
 */
#[Operation('yorvoid.group.unassign_role', 'Take a role from a group', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: YorvoIDDeclarations::ROLES_MANAGE)]
final readonly class UnassignGroupRole implements Command
{
    public function __construct(
        public Realm $realm,
        public string $group,
        public string $role,
        public ?ResourceRef $scope = null,
    ) {
    }
}
