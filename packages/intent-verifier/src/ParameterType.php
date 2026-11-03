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
            // Only what a person can see, so the signing page shows all of it: no control, format (zero-width,
            // bidirectional), private-use or unassigned characters, nor line or paragraph separators (INTENTS-09).
            self::String => \is_string($value) && mb_check_encoding($value, 'UTF-8') && 1 !== preg_match('/[\p{Cc}\p{Cf}\p{Co}\p{Cn}\p{Zl}\p{Zp}]/u', $value),
            self::Integer => \is_int($value) && abs($value) <= CanonicalJson::MAX_INTEGER,
            self::Boolean => \is_bool($value),
        };
    }
}
