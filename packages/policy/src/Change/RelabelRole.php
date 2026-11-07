<?php

declare(strict_types=1);

namespace YorvoID\Policy\Change;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Policy\Role;
use YorvoID\Policy\YorvoIDDeclarations;

/**
 * Relabels a role created in the realm; a declared one keeps its owner's label.
 *
 * @implements Command<Role>
 *
 * @api
 */
#[Operation('yorvoid.role.relabel', 'Relabel a role', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: YorvoIDDeclarations::ROLES_MANAGE)]
final readonly class RelabelRole implements Command
{
    public function __construct(
        public Realm $realm,
        public string $name,
        public string $label,
    ) {
    }
}
