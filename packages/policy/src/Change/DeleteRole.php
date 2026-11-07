<?php

declare(strict_types=1);

namespace YorvoID\Policy\Change;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Policy\YorvoIDDeclarations;

/**
 * Deletes a role created in the realm: it's taken from everyone, with its changes. A declared role
 * can't be deleted.
 *
 * @implements Command<null>
 *
 * @api
 */
#[Operation('yorvoid.role.delete', 'Delete a role', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: YorvoIDDeclarations::ROLES_MANAGE)]
final readonly class DeleteRole implements Command
{
    public function __construct(
        public Realm $realm,
        public string $name,
    ) {
    }
}
