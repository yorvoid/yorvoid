<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\Selector;

/**
 * The resource's attribute has this value.
 *
 * @api
 *
 * @experimental
 */
final readonly class AttributeIs implements Atom
{
    public function __construct(
        public string $name,
        public string|int|bool $value,
    ) {
    }

    public function toArray(): array
    {
        return ['attribute' => $this->name, 'is' => $this->value];
    }
}
