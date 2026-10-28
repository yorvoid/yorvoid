<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * @api
 */
#[Operation('yorvoid.identifier.remove', 'Remove an email address or username of a principal', ability: 'yorvoid.principals.manage', inverse: 'yorvoid.identifier.set')]
final readonly class RemoveIdentifier
{
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public IdentifierKind $kind,
    ) {
    }
}
