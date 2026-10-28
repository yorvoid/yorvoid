<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Text;

final class TextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'ASCII' => ['grace@example.com', 'grace@example.com'];
        yield 'valid UTF-8' => ['Ана Ковачевска ✓', 'Ана Ковачевска ✓'];
        yield 'empty' => ['', ''];
        yield 'a lone continuation byte' => ["a\x80b", "a\u{FFFD}b"];
        yield 'a truncated character' => ["Ан\xD0", "Ан\u{FFFD}"];
        yield 'an overlong encoding' => ["\xC0\xAF", "\u{FFFD}\u{FFFD}"];
        yield 'a UTF-16 surrogate' => ["\xED\xA0\x80", "\u{FFFD}"];
        yield 'Latin-1' => ["caf\xE9", "caf\u{FFFD}"];
    }

    #[DataProvider('texts')]
    public function testMakesTextValidUtf8TheSameWayEverywhere(string $text, string $expected): void
    {
        $previous = ini_set('mbstring.substitute_character', '63');

        try {
            self::assertSame($expected, Text::utf8($text));
            self::assertSame(1, preg_match('//u', Text::utf8($text)));
        } finally {
            ini_set('mbstring.substitute_character', false === $previous ? 'none' : $previous);
        }
    }
}
