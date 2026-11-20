<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\Id;

/**
 * A credential's ID in YorvoID's registry; not the authenticator's own credential ID.
 *
 * @api
 */
final readonly class CredentialId implements \Stringable
{
    public function __construct(public Id $id)
    {
    }

    public static function fromString(string $id): self
    {
        if (!Id::isValid($id)) {
            throw new IntegratorError(\sprintf('"%s" is not a credential ID.', $id), 'Credential IDs are UUIDs, e.g. "01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8c".', 'concepts/credentials.md');
        }

        return new self(Id::fromString($id));
    }

    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }

    public function __toString(): string
    {
        return $this->id->toString();
    }
}
