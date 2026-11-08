<?php

declare(strict_types=1);

namespace YorvoID\Policy\Attribute;

/**
 * @api
 */
enum AttributeType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Strings = 'strings';

    /**
     * Whether the value is one of this type.
     */
    public function fits(mixed $value): bool
    {
        return match ($this) {
            self::String => \is_string($value),
            self::Integer => \is_int($value),
            self::Boolean => \is_bool($value),
            self::Strings => \is_array($value) && array_is_list($value) && [] === array_filter($value, static fn (mixed $item): bool => !\is_string($item)),
        };
    }
}
