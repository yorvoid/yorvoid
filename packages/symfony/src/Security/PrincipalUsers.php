<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\Security\Core\User\UserInterface;
use YorvoID\Core\Principal\Principal;

/**
 * The application's user for a principal, after YorvoID signed them in: implement it to sign in your
 * own users, and give its service ID as yorvoid.users. Without one, the user is a PrincipalUser.
 *
 * @api
 */
interface PrincipalUsers
{
    public function userFor(Principal $principal): UserInterface;
}
