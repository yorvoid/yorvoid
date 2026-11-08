<?php

declare(strict_types=1);

namespace YorvoID\Policy\Change;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\YorvoIDDeclarations;

/**
 * Takes a role from a principal: the one in the whole realm, or the one on the resource.
 *
 * @implements Command<bool> Whether it had it
 *
 * @api
 */
#[Operation('yorvoid.role.unassign', 'Take a role from someone', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: YorvoIDDeclarations::ROLES_MANAGE)]
final readonly class UnassignRole implements Command
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public string $role,
        public ?ResourceRef $scope = null,
    ) {
    }
}
