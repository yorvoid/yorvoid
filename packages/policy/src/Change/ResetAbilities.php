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
 * Back to the declared defaults: for these abilities, or for all of them.
 *
 * @implements Command<AbilityChanges> The realm's changes now
 *
 * @api
 */
#[Operation('yorvoid.ability.reset', 'Restore the default abilities', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: YorvoIDDeclarations::PERMISSIONS_MANAGE)]
final readonly class ResetAbilities implements Command
{
    /**
     * @param list<string>|null $abilities Null for all
     */
    public function __construct(
        public Realm $realm,
        public ?array $abilities = null,
    ) {
    }
}
