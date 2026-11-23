<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Crypto;

use Innmind\BlackBox\PHPUnit\BlackBox;
use Innmind\BlackBox\Set;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Crypto\CryptoProvider;
use YorvoID\Core\Crypto\Mac;
use YorvoID\Core\Secret;

/**
 * HMAC-SHA256 composed over OpenSSL's SHA-256: RFC 4231's test cases, and PHP's own HMAC wherever both
 * run.
 */
final class MacTest extends TestCase
{
    use BlackBox;

    /**
     * RFC 4231 §4.2–4.8: key, data, HMAC-SHA256 (test case 5 truncated to 128 bits, as the RFC gives it).
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function vectors(): iterable
    {
        yield 'test case 1' => [str_repeat("\x0b", 20), 'Hi There', 'b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7'];
        yield 'test case 2' => ['Jefe', 'what do ya want for nothing?', '5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843'];
        yield 'test case 3' => [str_repeat("\xaa", 20), str_repeat("\xdd", 50), '773ea91e36800e46854db8ebd09181a72959098b3ef8c122d9635514ced565fe'];
        yield 'test case 4' => [(string) hex2bin('0102030405060708090a0b0c0d0e0f10111213141516171819'), str_repeat("\xcd", 50), '82558a389a443c0ea4cc819899f2083a85f0faa3e578f8077a2e3ff46729665b'];
        yield 'test case 5' => [str_repeat("\x0c", 20), 'Test With Truncation', 'a3b6167473100ee06e0c796c2955552b'];
        yield 'test case 6' => [str_repeat("\xaa", 131), 'Test Using Larger Than Block-Size Key - Hash Key First', '60e431591ee0b67f0d8a26aacbf5b77f8e0bc6213728c5140546040f0ee37f54'];
        yield 'test case 7' => [str_repeat("\xaa", 131), 'This is a test using a larger than block-size key and a larger than block-size data. The key needs to be hashed before being used by the HMAC algorithm.', '9b09ffa71b942fcb27635fbcd5b0e944bfdc63644f0713938a7f51535c3a35e2'];
    }

    #[DataProvider('vectors')]
    public function testRfc4231(string $key, string $data, string $expected): void
    {
        self::assertSame($expected, substr(bin2hex(Mac::sha256(Secret::fromString($key), $data)), 0, \strlen($expected)));
    }

    public function testItIsHmacForAnyKeyAndMessage(): void
    {
        self::forAll(Set::strings()->between(1, 200), Set::strings()->between(0, 300))->then(static function (string $key, string $message): void {
            self::assertSame(hash_hmac('sha256', $message, $key, true), Mac::sha256(Secret::fromString($key), $message));
        });
    }

    public function testTheProviderProbesIt(): void
    {
        self::assertTrue(CryptoProvider::probe()->hmac, 'RFC 4231\'s vector, at boot.');
    }
}
