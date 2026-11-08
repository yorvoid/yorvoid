<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Exception\IntegratorError;

/**
 * What a request is about, by its type and ID, e.g. site:42: what a role is assigned on, and what a
 * relation joins. YorvoID never loads the resource itself.
 *
 * @api
 */
final readonly class ResourceRef implements \Stringable
{
    private const string TYPE = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D';

    /**
     * @throws IntegratorError When the type isn't snake_case, or the ID is empty or longer than 255 bytes
     */
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if (1 !== preg_match(self::TYPE, $type) || \strlen($type) > 64) {
            throw new IntegratorError(\sprintf('The resource type "%s" is invalid.', $type), 'Use up to 64 lowercase letters, digits and underscores, starting with a letter, e.g. "site" or "event_series".', 'concepts/authorization.md#resources');
        }

        if ('' === $id || \strlen($id) > 255) {
            throw new IntegratorError(\sprintf('The ID of a "%s" must be 1 to 255 bytes.', $type), 'Pass the resource\'s own ID, e.g. its UUID or its key.', 'concepts/authorization.md#resources');
        }
    }

    /**
     * From "type:id", as __toString() writes it.
     *
     * @throws IntegratorError
     */
    public static function parse(string $reference): self
    {
        $parts = explode(':', $reference, 2);

        if (2 !== \count($parts)) {
            throw new IntegratorError(\sprintf('"%s" isn\'t a resource reference.', $reference), 'Write it as type:id, e.g. "site:42".', 'concepts/authorization.md#resources');
        }

        return new self($parts[0], $parts[1]);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    public function __toString(): string
    {
        return $this->type.':'.$this->id;
    }
}
