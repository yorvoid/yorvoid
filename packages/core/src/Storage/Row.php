<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage;

/**
 * Reads typed values from a database row. Drivers return numbers as integers or strings; anything
 * else means the row isn't what YorvoID wrote, and is refused rather than misread.
 *
 * @internal
 */
final class Row
{
    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        return \is_string($value) ? $value : throw self::unexpected($column, $value, 'a string');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableString(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return null === $value || \is_string($value) ? $value : throw self::unexpected($column, $value, 'a string or null');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        return match (true) {
            \is_int($value) => $value,
            \is_string($value) && 1 === preg_match('/^-?[0-9]+$/D', $value) => (int) $value,
            default => throw self::unexpected($column, $value, 'an integer'),
        };
    }

    private static function unexpected(string $column, mixed $value, string $expected): StorageException
    {
        return new StorageException(\sprintf('The column "%s" holds %s where %s was expected: the row wasn\'t written by YorvoID, or was changed.', $column, get_debug_type($value), $expected));
    }
}
