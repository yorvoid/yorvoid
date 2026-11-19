<?php

declare(strict_types=1);

namespace YorvoID\Policy\Change;

use YorvoID\Core\Authorization\ChangesAuthority;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Policy\YorvoIDDeclarations;
use YorvoID\Relations\Subject;

/**
 * Makes a principal (Subject::principal()), or another group's members (GroupRelations::members()), members of a group.
 *
 * @implements ChangesAuthority<null>
 *
 * @api
 */
#[Operation('yorvoid.group.add_member', 'Add a member to a group', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: YorvoIDDeclarations::ROLES_MANAGE, inverse: 'yorvoid.group.remove_member')]
final readonly class AddGroupMember implements ChangesAuthority
{
    public function __construct(
        public Realm $realm,
        public string $group,
        public Subject $member,
    ) {
    }
}
