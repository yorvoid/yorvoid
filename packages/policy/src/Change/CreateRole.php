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
 * Creates a role in the realm, optionally with another role's abilities, given to it as changes.
 *
 * @implements Command<Role>
 *
 * @api
 */
#[Operation('yorvoid.role.create', 'Create a role', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: YorvoIDDeclarations::ROLES_MANAGE, inverse: 'yorvoid.role.delete')]
final readonly class CreateRole implements Command
{
    /**
     * @param string|null $from The role whose abilities it starts with
     */
    public function __construct(
        public Realm $realm,
        public string $name,
        public string $label,
        public ?string $from = null,
    ) {
    }
}
