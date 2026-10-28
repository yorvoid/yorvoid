<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * Sets a principal's email address or username, replacing the one it had of that kind.
 *
 * @api
 */
#[Operation('yorvoid.identifier.set', 'Set an email address or username of a principal', ability: 'yorvoid.principals.manage', inverse: 'yorvoid.identifier.remove')]
final readonly class SetIdentifier
{
    /**
     * @param bool $verified Whether its owner has shown it's theirs; only the code that saw them do so
     *                       says true. Setting a different value than before is unverified otherwise
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public IdentifierKind $kind,
        public string $value,
        public bool $verified = false,
    ) {
    }
}
