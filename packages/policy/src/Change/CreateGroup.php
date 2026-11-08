<?php

declare(strict_types=1);

namespace YorvoID\Policy\Change;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Policy\YorvoIDDeclarations;

/**
 * Creates a group in the realm, or relabels it.
 *
 * @implements Command<null>
 *
 * @api
 */
#[Operation('yorvoid.group.create', 'Create a group', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: YorvoIDDeclarations::ROLES_MANAGE, inverse: 'yorvoid.group.delete')]
final readonly class CreateGroup implements Command
{
    public function __construct(
        public Realm $realm,
        public string $name,
        public string $label,
    ) {
    }
}
