<?php

declare(strict_types=1);

namespace YorvoID\Symfony;

/**
 * Whether an address stays on this site, as a path: for configured paths, and for where a flow returns
 * to. Browsers read "\" as "/", and drop tabs and line breaks from addresses, so "/\host", or "/", a tab
 * and "/host", would lead to another site; "//host" does on its own.
 *
 * @internal
 */
final class LocalPath
{
    /** The longest path a flow returns to */
    public const int MAX_LENGTH = 2048;

    public static function is(string $path): bool
    {
        return \strlen($path) <= self::MAX_LENGTH && mb_check_encoding($path, 'UTF-8') && 1 === preg_match('#^/(?![/\\\\])[^\\\\\x00-\x20\x7f]*$#D', $path);
    }
}
