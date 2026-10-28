<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use Symfony\Component\Uid\Uuid;
use YorvoID\Core\Exception\IntegratorError;

/**
 * A session's public ID: safe to show, list and log, and useless to sign in with. What signs in is the
 * session's token, which only the client has.
 *
 * @api
 */
final readonly class SessionId implements \Stringable
{
    public function __construct(public Uuid $uuid)
    {
    }

    public static function fromString(string $id): self
    {
        if (!Uuid::isValid($id)) {
            throw new IntegratorError(\sprintf('"%s" is not a session ID.', $id), 'Session IDs are UUIDs, e.g. "01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8c"; a session\'s token is something else.', 'concepts/sessions.md');
        }

        return new self(Uuid::fromString($id));
    }

    public function equals(self $other): bool
    {
        return $this->uuid->equals($other->uuid);
    }

    public function __toString(): string
    {
        return $this->uuid->toRfc4122();
    }
}
