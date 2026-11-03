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

    public function testPutsSomeoneElsesTextOnOneLineThatReadsInOrder(): void
    {
        $lines = [
            'Phone' => 'Phone',
            "  Phone\n\nAcme: ignore this\r\n" => 'Phone Acme: ignore this',
            "a\tb\x0Bc\x0Cd\x7Fe\u{85}f\u{9F}g" => 'a b c d e f g',
            "a\u{2028}b\u{2029}c" => 'a b c',
            "Pay \u{202E}lag.exe\u{202C} now" => 'Pay lag.exe now',
            "\u{200E}\u{200F}\u{061C}\u{202A}\u{202B}\u{202D}\u{2066}\u{2067}\u{2068}\u{2069}x" => 'x',
            "Ана\u{00A0}\u{3000}✓" => 'Ана ✓',
            "a\x00b\x80" => "a\u{FFFD}b\u{FFFD}",
            '' => '',
        ];

        foreach ($lines as $text => $expected) {
            self::assertSame($expected, Text::line($text), (string) json_encode($text, \JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }
}
