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
     * The text as valid UTF-8 that every database keeps: every invalid byte sequence replaced by U+FFFD,
     * the same way on every system (unlike mb_scrub(), which depends on an ini setting), and so is every
     * NUL, which PostgreSQL's text can't hold. Recording attacker-controlled text must never fail, or an
     * attacker could keep their attempts out of the security log.
     */
    public static function utf8(string $text): string
    {
        // Encoding a string as JSON can't fail with this flag, and decoding the result gives a string.
        $scrubbed = json_decode((string) json_encode($text, \JSON_INVALID_UTF8_SUBSTITUTE));
        \assert(\is_string($scrubbed));

        return str_replace("\0", "\u{FFFD}", $scrubbed);
    }

    /**
     * The text on one line, as someone else wrote it, safe to show among other text: valid UTF-8 (utf8()),
     * every control character and line or paragraph separator a space, and no bidirectional controls,
     * which could make it read in another order. Runs of spaces become one; no space at either end.
     */
    public static function line(string $text): string
    {
        $text = preg_replace(['/[\p{Cc}\x{2028}\x{2029}]/u', '/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '/\s+/u'], [' ', '', ' '], self::utf8($text));
        \assert(\is_string($text));

        return trim($text);
    }
}
