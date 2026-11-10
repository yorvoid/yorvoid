<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Key;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Crypto\Digest;
use YorvoID\Core\Key\KeyStatus;
use YorvoID\Core\Key\SigningKey;
use YorvoID\Core\Secret;

/**
 * Signing keys sign through OpenSSL (CRY-01), exactly as libsodium did: the same public keys from the
 * same seeds, the same signatures, so keys and checkpoints from before carry on. The hashes too.
 */
final class SigningKeyTest extends TestCase
{
    #[Group('ed25519-keys')]
    #[RequiresPhpExtension('sodium')]
    public function testOpenSslSignsAsLibsodiumDid(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $seed = random_bytes(32);
            $pair = sodium_crypto_sign_seed_keypair($seed);
            $key = new SigningKey('k', KeyStatus::Active, sodium_crypto_sign_publickey($pair), Secret::fromString($seed));
            $message = 0 === $i ? '' : random_bytes($i * 13);

            self::assertSame(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($pair)), $key->sign($message), 'Ed25519 is deterministic: the same signature.');
            self::assertTrue(sodium_crypto_sign_verify_detached($key->sign($message), $message, $key->publicKey));
            self::assertTrue($key->withoutPrivatePart()->verify($message, sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($pair))));
        }

        $generated = SigningKey::generate('g');
        self::assertNotNull($generated->seed);
        self::assertSame(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($generated->seed->reveal())), $generated->publicKey);
    }

    #[Group('ed25519-keys')]
    public function testAKnownVector(): void
    {
        // RFC 8032 §7.1, test 2.
        $key = new SigningKey('rfc', KeyStatus::Active, (string) hex2bin('3d4017c3e843895a92b70aa74d1b7ebc9c982ccf2ec4968cc0cd55f12af4660c'), Secret::fromString((string) hex2bin('4ccd089b28ff96da9db6c346ec114e0f5b8a319f35aba624da8cf6ed4fb8a6fb')));

        self::assertSame('92a009a9f0d4cab8720e820b5f642540a2b27b5416503f8fb3762223ebdb69da085ac1e43e15996e458f3613d0f11d8c387b2eaeb4302aeeb00d291612bb0c00', bin2hex($key->sign("\x72")));
        self::assertTrue($key->verify("\x72", $key->sign("\x72")));
        self::assertFalse($key->verify("\x73", $key->sign("\x72")));
        self::assertFalse($key->verify("\x72", substr($key->sign("\x72"), 1)), 'A signature of another length.');
        self::assertFalse($key->verify("\x72", str_repeat("\0", 64)));
    }

    public function testTheHashesAreSha256(): void
    {
        self::assertSame(hash('sha256', 'abc'), Digest::sha256('abc'));
        self::assertSame(hash('sha256', 'abc', true), Digest::sha256('abc', true));
        self::assertSame(hash('sha256', ''), Digest::of('sha256', ''));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('YorvoID doesn\'t hash with md5.');
        Digest::of('md5', 'abc');
    }
}
