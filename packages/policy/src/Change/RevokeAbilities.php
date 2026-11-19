<?php

declare(strict_types=1);

namespace YorvoID\Policy\Change;

use YorvoID\Core\Authorization\ChangesAuthority;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Policy\AbilityChanges;
use YorvoID\Policy\YorvoIDDeclarations;

/**
 * Takes these abilities from a role in the realm; recorded only where it differs from the defaults.
 *
 * @implements ChangesAuthority<AbilityChanges> The realm's changes now
 *
 * @api
 */
#[Operation('yorvoid.ability.revoke', 'Take abilities from a role', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: YorvoIDDeclarations::PERMISSIONS_MANAGE)]
final readonly class RevokeAbilities implements ChangesAuthority
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
