<?php

declare(strict_types=1);

namespace YorvoID\Authn;

/**
 * Codes people read, write down and type: random characters of Crockford's base 32, five bits each, in
 * groups joined by hyphens. Case, hyphens and spaces don't matter, and O, I and L count as 0, 1 and 1.
 *
 * @internal
 */
final class HumanCode
{
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * @param positive-int $length Characters; each is 5 random bits
     * @param positive-int $group  Characters per group
     */
    public static function random(int $length, int $group): string
    {
        $code = '';

        foreach (str_split(random_bytes($length)) as $byte) {
            // 256 is a multiple of 32, so every character is equally likely.
            $code .= self::ALPHABET[\ord($byte) % 32];
        }

        return implode('-', str_split($code, $group));
    }

    /**
     * The code as it was given out, from what someone typed.
     */
    public static function normalize(string $code): string
    {
        return strtr(strtoupper(str_replace(['-', ' '], '', $code)), ['O' => '0', 'I' => '1', 'L' => '1']);
    }
}
