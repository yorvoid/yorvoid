<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * The JSON Canonicalization Scheme (RFC 8785) for the values intents hold: objects, lists, strings,
 * integers, booleans and null. One value has exactly one encoding, so a signature over it means one
 * thing (T-INT-5).
 *
 * Within that subset the encoding is RFC 8785's exactly: no whitespace, object members sorted by the
 * UTF-16 code units of their names, strings escaped as ECMAScript does. Numbers other than integers
 * of at most 2^53 - 1 are refused rather than approximated, and so are strings that aren't UTF-8.
 *
 * A PHP array is a list when its keys are 0, 1, 2…, and an object otherwise; an empty object is
 * `new \stdClass()`.
 *
 * @api
 */
final class CanonicalJson
{
    /** The largest integer every JSON parser reads exactly */
    public const int MAX_INTEGER = 9_007_199_254_740_991;

    private const int MAX_DEPTH = 32;

    public static function encode(mixed $value): string
    {
        return self::value($value, 0);
    }

    private static function value(mixed $value, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new ConfigurationError(\sprintf('A value nests deeper than %d levels.', self::MAX_DEPTH));
        }

        return match (true) {
            null === $value => 'null',
            true === $value => 'true',
            false === $value => 'false',
            \is_int($value) => self::integer($value),
            \is_string($value) => self::string($value),
            \is_array($value) && array_is_list($value) => '['.implode(',', array_map(static fn (mixed $item): string => self::value($item, $depth + 1), $value)).']',
            \is_array($value) => self::object($value, $depth),
            $value instanceof \stdClass => self::object(get_object_vars($value), $depth),
            default => throw new ConfigurationError(\sprintf('A %s can\'t be part of an intent: use strings, integers, booleans, null, lists and objects.', get_debug_type($value))),
        };
    }

    private static function integer(int $value): string
    {
        if ($value > self::MAX_INTEGER || $value < -self::MAX_INTEGER) {
            throw new ConfigurationError(\sprintf('The integer %d is beyond what JSON parsers read exactly; pass it as a string.', $value));
        }

        return (string) $value;
    }

    private static function string(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new ConfigurationError('A string isn\'t UTF-8.');
        }

        // ECMAScript's escaping: only the quote, the backslash and control characters; \b \f \n \r \t
        // short, the rest as \u00xx in lowercase. Everything else as it is, slashes and line separators too.
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_LINE_TERMINATORS | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<mixed> $members
     */
    private static function object(array $members, int $depth): string
    {
        $sorted = [];

        foreach (array_keys($members) as $name) {
            $sorted[self::utf16((string) $name)] = $name;
        }

        // Byte by byte, never as numbers.
        ksort($sorted, \SORT_STRING);

        return '{'.implode(',', array_map(static fn (int|string $name): string => self::string((string) $name).':'.self::value($members[$name], $depth + 1), $sorted)).'}';
    }

    /**
     * The name as UTF-16 code units, big-endian: compared byte by byte, they sort as RFC 8785 sorts.
     */
    private static function utf16(string $name): string
    {
        return mb_convert_encoding($name, 'UTF-16BE', 'UTF-8');
    }
}
