<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Uuid;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;

/**
 * Loads the application's user for a principal ID (PLT-011): the user provider of a firewall whose
 * people YorvoID signs in ("yorvoid" in security.yaml's providers). The user comes from
 * PrincipalUsers, so it's a PrincipalUser or the application's own, and is loaded again on every
 * request; a principal that isn't active any more isn't found.
 *
 * @implements UserProviderInterface<UserInterface>
 *
 * @api
 */
final readonly class PrincipalUserProvider implements UserProviderInterface
{
    public function __construct(
        private Principals $principals,
        private Realm $realm,
        private PrincipalUsers $users,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $principal = Uuid::isValid($identifier) ? $this->principals->get($this->realm, PrincipalId::fromString($identifier)) : null;

        if (null === $principal || !$principal->isActive()) {
            throw new UserNotFoundException();
        }

        $user = $this->users->userFor($principal);

        if ((string) $principal->id !== $user->getUserIdentifier()) {
            throw new IntegratorError(\sprintf('The user %s gave for principal %s is identified as "%s".', get_debug_type($this->users), $principal->id, $user->getUserIdentifier()), 'Return the principal\'s ID from your user\'s getUserIdentifier(): it\'s how the firewall loads them again.', 'concepts/symfony.md#your-own-users');
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    /**
     * Any class: the firewall's users are whatever PrincipalUsers gives.
     */
    public function supportsClass(string $class): bool
    {
        return true;
    }
}
