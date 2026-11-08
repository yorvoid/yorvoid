<?php

declare(strict_types=1);

namespace YorvoID\Policy\Change;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Policy\YorvoIDDeclarations;
use YorvoID\Relations\Subject;

/**
 * Takes a member out of a group.
 *
 * @implements Command<bool> Whether it was one
 *
 * @api
 */
#[Operation('yorvoid.group.remove_member', 'Remove a member from a group', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: YorvoIDDeclarations::ROLES_MANAGE)]
final readonly class RemoveGroupMember implements Command
{
    public function __construct(
        public Realm $realm,
        public string $group,
        public Subject $member,
    ) {
    }
}
