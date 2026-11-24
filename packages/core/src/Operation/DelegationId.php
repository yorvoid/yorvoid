<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\Id;

/**
 * A delegation's ID: what an actor acting under one names (ActsUnderDelegation).
 *
 * @api
 *
 * @experimental
 */
final readonly class DelegationId implements \Stringable
{
    public function __construct(public Id $id)
    {
    }

    public static function fromString(string $id): self
    {
        if (!Id::isValid($id)) {
            throw new IntegratorError(\sprintf('"%s" is not a delegation ID.', $id), 'Delegation IDs are UUIDs, e.g. "01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8c".', 'concepts/delegation.md');
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
