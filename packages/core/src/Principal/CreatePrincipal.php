<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;

/**
 * @api
 */
#[Operation('yorvoid.principal.create', 'Create a principal', ability: 'yorvoid.principals.manage', inverse: 'yorvoid.principal.delete')]
final readonly class CreatePrincipal
{
    /**
     * @param bool $emailVerified Whether the person has shown the email address is theirs; see SetIdentifier
     */
    public function __construct(
        public Realm $realm,
        public PrincipalKind $kind,
        public PrincipalStatus $status = PrincipalStatus::Active,
        public ?string $email = null,
        public ?string $username = null,
        public bool $emailVerified = false,
    ) {
    }
}
