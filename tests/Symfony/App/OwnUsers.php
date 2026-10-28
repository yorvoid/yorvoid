<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use YorvoID\Core\Principal\Principal;
use YorvoID\Symfony\Security\PrincipalUsers;

/**
 * An application's own users, for principals YorvoID signed in.
 */
final class OwnUsers implements PrincipalUsers
{
    public function userFor(Principal $principal): UserInterface
    {
        return new InMemoryUser((string) $principal->id, null, ['ROLE_OWN']);
    }
}
