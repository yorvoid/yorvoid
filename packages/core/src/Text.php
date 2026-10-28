<?php

declare(strict_types=1);

namespace YorvoID\Core;

/**
 * Text that YorvoID records as given by someone else, e.g. what a person typed into a failed sign-in.
 *
 * @internal
 */
final class Text
{
    /**
     * The text as valid UTF-8: every invalid byte sequence replaced by U+FFFD, the same way on every
     * system (unlike mb_scrub(), which depends on an ini setting). Recording attacker-controlled text
     * must never fail, or an attacker could keep their attempts out of the security log.
     */
    public static function utf8(string $text): string
    {
        // Encoding a string as JSON can't fail with this flag, and decoding the result gives a string.
        $scrubbed = json_decode((string) json_encode($text, \JSON_INVALID_UTF8_SUBSTITUTE));
        \assert(\is_string($scrubbed));

        return $scrubbed;
    }
}
