<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * @api
 */
enum ParameterType: string
{
    case String = 'string';

    case Integer = 'integer';

    case Boolean = 'boolean';

    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::String => \is_string($value) && mb_check_encoding($value, 'UTF-8'),
            self::Integer => \is_int($value) && abs($value) <= CanonicalJson::MAX_INTEGER,
            self::Boolean => \is_bool($value),
        };
    }
}
