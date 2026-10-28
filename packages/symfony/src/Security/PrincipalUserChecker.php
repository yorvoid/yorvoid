<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses principals that aren't active, e.g. suspended ones (PLT-011): the user checker of a
 * firewall whose users are PrincipalUsers.
 *
 * @api
 */
final class PrincipalUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof PrincipalUser && !$user->principal->isActive()) {
            throw new CustomUserMessageAccountStatusException('This account can\'t sign in.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
