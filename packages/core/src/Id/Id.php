<?php

declare(strict_types=1);

namespace YorvoID\Core\Id;

use Symfony\Component\Uid\Uuid;
use YorvoID\Core\Exception\IntegratorError;

/**
 * The ID of one of YorvoID's records: a UUID, in its canonical text form (RFC 9562, lowercase). What
 * it's made of, e.g. the time in a UUIDv7, isn't part of it: compare IDs, store and show their text.
 * Typed IDs, e.g. PrincipalId, wrap one.
 *
 * @api
 */
final readonly class Id implements \Stringable
{
    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @throws IntegratorError When it isn't a UUID
     */
    public static function fromString(string $id): self
    {
        if (!self::isValid($id)) {
            throw new IntegratorError(\sprintf('"%s" is not an ID.', $id), 'YorvoID\'s IDs are UUIDs, e.g. "01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8c".', 'concepts/principals.md');
        }

        return new self(Uuid::fromString($id)->toRfc4122());
    }

    /**
     * Whether it's a UUID in any of its text forms.
     */
    public static function isValid(string $id): bool
    {
        return Uuid::isValid($id);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
