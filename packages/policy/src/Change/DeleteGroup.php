<?php

declare(strict_types=1);

namespace YorvoID\Policy\Change;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Policy\YorvoIDDeclarations;

/**
 * Deletes a group: its members leave it, with the roles given to it.
 *
 * @implements Command<null>
 *
 * @api
 */
#[Operation('yorvoid.group.delete', 'Delete a group', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: YorvoIDDeclarations::ROLES_MANAGE)]
final readonly class DeleteGroup implements Command
{
    public function __construct(
        public Realm $realm,
        public string $name,
    ) {
    }
}
