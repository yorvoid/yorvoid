<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\Security\Core\User\UserInterface;
use YorvoID\Core\Principal\Principal;

/**
 * @internal
 */
final class DefaultPrincipalUsers implements PrincipalUsers
{
    public function userFor(Principal $principal): UserInterface
    {
        return new PrincipalUser($principal);
    }
}
