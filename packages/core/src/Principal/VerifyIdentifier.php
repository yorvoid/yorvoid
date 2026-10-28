<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * Marks a principal's identifier as shown to be theirs, e.g. after they opened a link sent to the
 * address. Only the code that saw them do so runs it.
 *
 * @api
 */
#[Operation('yorvoid.identifier.verify', 'Mark an identifier as verified by its owner')]
final readonly class VerifyIdentifier
{
    /**
     * @param string $value The value that was verified: if the identifier changed since, nothing is marked
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public IdentifierKind $kind,
        public string $value,
    ) {
    }
}
