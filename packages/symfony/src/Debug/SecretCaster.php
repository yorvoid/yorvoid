<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Debug;

use Symfony\Component\VarDumper\Caster\Caster;
use Symfony\Component\VarDumper\Cloner\Stub;
use YorvoID\Core\Secret;

/**
 * Shows a Secret in Symfony's dumper as "[redacted]", wherever it is: alone, or in an IssuedToken, a
 * StartedSession or a key.
 *
 * @internal
 */
final class SecretCaster
{
    /**
     * @param array<array-key, mixed> $properties
     *
     * @return array<string, string>
     */
    public static function cast(Secret $secret, array $properties, Stub $stub, bool $isNested): array
    {
        return [Caster::PREFIX_VIRTUAL.'value' => '[redacted]'];
    }
}
