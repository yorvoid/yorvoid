<?php

declare(strict_types=1);

namespace YorvoID\Tests\IntentVerifier;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\IntentVerifier\CanonicalJson;
use YorvoID\IntentVerifier\ConfigurationError;
use YorvoID\IntentVerifier\Intent;

/**
 * RFC 8785 for the values intents hold (INT-001, T-INT-5).
 */
final class CanonicalJsonTest extends TestCase
{
    public function testSortsMembersByTheirUtf16CodeUnits(): void
    {
        // RFC 8785, section 3.2.3.
        $members = ["\u{20ac}" => 'Euro Sign', "\r" => 'Carriage Return', "\u{fb33}" => 'Hebrew Letter Dalet With Dagesh', '1' => 'One', "\u{1f600}" => 'Emoji: Grinning Face', "\u{80}" => 'Control', "\u{f6}" => 'Latin Small Letter O With Diaeresis'];

        self::assertSame(
            "{\"\\r\":\"Carriage Return\",\"1\":\"One\",\"\u{80}\":\"Control\",\"\u{f6}\":\"Latin Small Letter O With Diaeresis\",\"\u{20ac}\":\"Euro Sign\",\"\u{1f600}\":\"Emoji: Grinning Face\",\"\u{fb33}\":\"Hebrew Letter Dalet With Dagesh\"}",
            CanonicalJson::encode($members),
        );
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function values(): iterable
    {
        yield 'literals' => [[null, true, false], '[null,true,false]'];
        yield 'integers' => [[0, -1, CanonicalJson::MAX_INTEGER, -CanonicalJson::MAX_INTEGER], '[0,-1,9007199254740991,-9007199254740991]'];
        yield 'escapes, as ECMAScript' => ["\"\\\x08\x0c\n\r\t\x00\x1f\x7f", '"\"\\\\\b\f\n\r\t\u0000\u001f'."\x7f".'"'];
        yield 'slashes and line separators as they are' => ["a/b\u{2028}\u{2029}é€😀", "\"a/b\u{2028}\u{2029}é€😀\""];
        yield 'nested' => [['b' => [1, ['d' => 2, 'c' => [3]]], 'a' => 'x'], '{"a":"x","b":[1,{"c":[3],"d":2}]}'];
        yield 'an empty list' => [[], '[]'];
        yield 'an empty object' => [new \stdClass(), '{}'];
        yield 'an object' => [(object) ['b' => 1, 'a' => new \stdClass()], '{"a":{},"b":1}'];
        yield 'numeric names' => [['10' => 'a', '9' => 'b'], '{"10":"a","9":"b"}'];
    }

    #[DataProvider('values')]
    public function testEncodesExactlyOneWay(mixed $value, string $json): void
    {
        self::assertSame($json, CanonicalJson::encode($value));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function refused(): iterable
    {
        yield 'a float' => [[1.5], 'A float can\'t be part of an intent: use strings, integers, booleans, null, lists and objects.'];
        yield 'too large an integer' => [CanonicalJson::MAX_INTEGER + 1, 'The integer 9007199254740992 is beyond what JSON parsers read exactly; pass it as a string.'];
        yield 'too small an integer' => [-CanonicalJson::MAX_INTEGER - 1, 'The integer -9007199254740992 is beyond what JSON parsers read exactly; pass it as a string.'];
        yield 'not UTF-8' => ["\xc3\x28", 'A string isn\'t UTF-8.'];
        yield 'a name not UTF-8' => [["\xc3\x28" => 1], 'A string isn\'t UTF-8.'];
        yield 'an object of a class' => [new \ArrayObject(), 'A ArrayObject can\'t be part of an intent'];
    }

    #[DataProvider('refused')]
    public function testRefusesWhatHasNoSingleEncoding(mixed $value, string $message): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage($message);

        CanonicalJson::encode($value);
    }

    public function testRefusesDeepNesting(): void
    {
        $value = 'x';

        for ($i = 0; $i < 32; ++$i) {
            $value = [$value];
        }

        self::assertSame(str_repeat('[', 32).'"x"'.str_repeat(']', 32), CanonicalJson::encode($value));

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('A value nests deeper than 32 levels.');

        CanonicalJson::encode([$value]);
    }

    public function testRefusesDeepNestingThroughObjects(): void
    {
        $value = 'x';

        for ($i = 0; $i < 32; ++$i) {
            $value = ['a' => $value];
        }

        self::assertSame(str_repeat('{"a":', 32).'"x"'.str_repeat('}', 32), CanonicalJson::encode($value));

        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage('A value nests deeper than 32 levels.');

        CanonicalJson::encode(['a' => $value]);
    }

    public function testAnIntentsChallengeIsItsDomainSeparatedHash(): void
    {
        $intent = new Intent('acme.site.remove', ['site' => 'jane', 'backup' => true, 'days' => 30], 'acme-verifier', 'bm9uY2U', new \DateTimeImmutable('@1793210400'), new \DateTimeImmutable('@1793210700'));

        // Both computed by Python's json and hashlib, independently of this implementation.
        self::assertSame('{"action":"acme.site.remove","audience":"acme-verifier","expires_at":1793210700,"issued_at":1793210400,"nonce":"bm9uY2U","parameters":{"backup":true,"days":30,"site":"jane"}}', $intent->canonical());
        self::assertSame('9f050732ce14f6ece05ded083c8ba0c81f9327c39c5933884875ef18362d8fab', bin2hex($intent->challenge()));
        self::assertStringContainsString('"parameters":{}', new Intent('acme.site.list', [], 'acme-verifier', 'n', new \DateTimeImmutable('@0'), new \DateTimeImmutable('@1'))->canonical(), 'No parameters are an empty object, not a list.');
    }
}
