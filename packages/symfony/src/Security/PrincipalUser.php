<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\Security\Core\User\UserInterface;
use YorvoID\Core\Principal\Principal;

/**
 * A Symfony user that is a YorvoID principal, for applications without a user class of their own.
 * Its identifier is the principal's ID; its role, ROLE_USER.
 *
 * In a session it's only its identifier: the principal is read again on the next request
 * (PrincipalUserProvider::refreshUser()), so a session doesn't depend on the shape of YorvoID's value
 * objects. Until then, a user read back from a session has no principal.
 *
 * @api
 */
final readonly class PrincipalUser implements UserInterface
{
    /** @var non-empty-string */
    private string $identifier;

    public function __construct(
        public Principal $principal,
    ) {
        $this->identifier = self::identifier((string) $principal->id);
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    /**
     * Symfony 7's UserInterface asks for it; there are no credentials to erase.
     */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    /**
     * @return array{identifier: non-empty-string}
     */
    public function __serialize(): array
    {
        return ['identifier' => $this->identifier];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->identifier = self::identifier(\is_string($data['identifier'] ?? null) ? $data['identifier'] : '');
    }

    /**
     * @return non-empty-string
     */
    private static function identifier(string $identifier): string
    {
        if ('' === $identifier) {
            throw new \UnexpectedValueException('A user that was serialized without its identifier.');
        }

        return $identifier;
    }
}
