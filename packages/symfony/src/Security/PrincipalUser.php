<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\Security\Core\User\UserInterface;
use YorvoID\Core\Principal\Principal;

/**
 * A Symfony user that is a YorvoID principal, for applications without a user class of their own.
 * Its identifier is the principal's ID; its role, ROLE_USER.
 *
 * @api
 */
final readonly class PrincipalUser implements UserInterface
{
    public function __construct(
        public Principal $principal,
    ) {
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->principal->id->uuid->toRfc4122();
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
