<?php

declare(strict_types=1);

namespace YorvoID\Tests\Passkeys;

use CBOR\ByteStringObject;
use CBOR\CBORObject;
use CBOR\ListObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\UnsignedIntegerObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Passkeys\PublicKeyCheck;

final class PublicKeyCheckTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function keys(): iterable
    {
        [$x, $y] = self::p256();
        yield 'P-256' => [self::cose([1 => 2, 3 => -7, -1 => 1, -2 => $x, -3 => $y])];
        yield 'Ed25519' => [self::cose([1 => 1, 3 => -8, -1 => 6, -2 => sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())])];
        yield 'RSA 2048' => [self::rsa(2048)];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        [$x, $y] = self::p256();
        $okp = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $rsa = self::details(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048])['rsa'];
        \assert(\is_array($rsa) && \is_string($rsa['n']));

        yield 'a point off the curve' => [self::cose([1 => 2, 3 => -7, -1 => 1, -2 => $x, -3 => ~$y])];
        yield 'the point at zero' => [self::cose([1 => 2, 3 => -7, -1 => 1, -2 => str_repeat("\0", 32), -3 => str_repeat("\0", 32)])];
        yield '31-byte coordinates' => [self::cose([1 => 2, 3 => -7, -1 => 1, -2 => substr($x, 1), -3 => substr($y, 1)])];
        yield 'another curve' => [self::cose([1 => 2, 3 => -7, -1 => 2, -2 => $x, -3 => $y])];
        yield 'ES256 on an OKP key' => [self::cose([1 => 1, 3 => -7, -1 => 6, -2 => $okp])];
        yield 'EdDSA on an EC2 key' => [self::cose([1 => 2, 3 => -8, -1 => 1, -2 => $x, -3 => $y])];
        yield 'Ed448 under EdDSA' => [self::cose([1 => 1, 3 => -8, -1 => 7, -2 => str_repeat("\1", 57)])];
        yield 'X25519 under EdDSA' => [self::cose([1 => 1, 3 => -8, -1 => 4, -2 => $okp])];
        yield 'a short Ed25519 key' => [self::cose([1 => 1, 3 => -8, -1 => 6, -2 => substr($okp, 1)])];
        yield 'RSA 1024' => [self::rsa(1024)];
        yield 'RSA 1024 padded to 2048 bits' => [self::rsa(1024, 256)];
        yield 'P-384 under ES256' => [self::p384()];
        yield 'RSA with e = 1' => [self::cose([1 => 3, 3 => -257, -1 => $rsa['n'], -2 => "\x01"])];
        yield 'RSA with an empty modulus' => [self::cose([1 => 3, 3 => -257, -1 => '', -2 => "\x01\x00\x01"])];
        yield 'RS256 on an EC2 key' => [self::cose([1 => 2, 3 => -257, -1 => 1, -2 => $x, -3 => $y])];
        yield 'an algorithm not offered' => [self::cose([1 => 2, 3 => -35, -1 => 2, -2 => $x, -3 => $y])];
        yield 'no algorithm' => [self::cose([1 => 2, -1 => 1, -2 => $x, -3 => $y])];
        yield 'a list' => [(string) ListObject::create([UnsignedIntegerObject::create(2)])];
        yield 'not CBOR' => ["\xff"];
        yield 'nothing' => [''];
    }

    #[DataProvider('keys')]
    public function testAcceptsKeysThatVerify(string $key): void
    {
        self::assertTrue(PublicKeyCheck::accepts($key));
    }

    #[DataProvider('refused')]
    public function testRefusesWeakOrBrokenKeys(string $key): void
    {
        self::assertFalse(PublicKeyCheck::accepts($key));
    }

    /**
     * @return array{string, string}
     */
    private static function p256(): array
    {
        $details = self::details(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        \assert(\is_array($details['ec']) && \is_string($details['ec']['x']) && \is_string($details['ec']['y']));

        return [str_pad($details['ec']['x'], 32, "\0", \STR_PAD_LEFT), str_pad($details['ec']['y'], 32, "\0", \STR_PAD_LEFT)];
    }

    /**
     * @param int $padded The modulus's length in bytes, with leading zeros
     */
    private static function rsa(int $bits, int $padded = 0): string
    {
        $details = self::details(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
        \assert(\is_array($details['rsa']) && \is_string($details['rsa']['n']) && \is_string($details['rsa']['e']));

        return self::cose([1 => 3, 3 => -257, -1 => str_pad($details['rsa']['n'], $padded, "\0", \STR_PAD_LEFT), -2 => $details['rsa']['e']]);
    }

    private static function p384(): string
    {
        $details = self::details(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
        \assert(\is_array($details['ec']) && \is_string($details['ec']['x']) && \is_string($details['ec']['y']));

        return self::cose([1 => 2, 3 => -7, -1 => 2, -2 => str_pad($details['ec']['x'], 48, "\0", \STR_PAD_LEFT), -3 => str_pad($details['ec']['y'], 48, "\0", \STR_PAD_LEFT)]);
    }

    /**
     * @param array<string, int|string> $options
     *
     * @return array<mixed>
     */
    private static function details(array $options): array
    {
        $key = openssl_pkey_new($options);
        \assert($key instanceof \OpenSSLAsymmetricKey);
        $details = openssl_pkey_get_details($key);
        \assert(\is_array($details));

        return $details;
    }

    /**
     * @param array<int, int|string> $entries Integers as integers, everything else as bytes
     */
    private static function cose(array $entries): string
    {
        $map = MapObject::create();

        foreach ($entries as $label => $value) {
            $map->add(self::integer($label), \is_int($value) ? self::integer($value) : ByteStringObject::create($value));
        }

        return (string) $map;
    }

    private static function integer(int $value): CBORObject
    {
        return $value < 0 ? NegativeIntegerObject::create($value) : UnsignedIntegerObject::create($value);
    }
}
