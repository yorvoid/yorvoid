<?php

declare(strict_types=1);

namespace YorvoID\Policy\Change;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Policy\AbilityChanges;
use YorvoID\Policy\YorvoIDDeclarations;

/**
 * Gives a role exactly these abilities in the realm: the others it has are taken.
 *
 * @implements Command<AbilityChanges> The realm's changes now
 *
 * @api
 */
#[Operation('yorvoid.ability.set', 'Set the abilities of a role', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: YorvoIDDeclarations::PERMISSIONS_MANAGE)]
final readonly class SetAbilities implements Command
{
    /**
     * @param list<string> $abilities
     */
    public function __construct(
        public Realm $realm,
        public string $role,
        public array $abilities,
    ) {
    }
}
