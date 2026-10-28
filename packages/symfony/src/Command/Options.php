<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Command;

/**
 * @internal
 */
final class Options
{
    /**
     * An argument or option with a text default, or a required one: always text.
     */
    public static function string(mixed $value): string
    {
        \assert(\is_string($value));

        return $value;
    }

    /**
     * An argument that takes many values: always a list of text.
     *
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        \assert(\is_array($value) && array_is_list($value));

        // The console gives text: these are what was typed.
        /** @var list<string> $strings */
        $strings = $value;

        return $strings;
    }
}
