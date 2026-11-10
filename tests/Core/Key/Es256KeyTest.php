<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Key;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Key\KeyAlgorithm;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Key\KeyStatus;
use YorvoID\Core\Key\SigningKey;
use YorvoID\Core\Secret;

/**
 * ES256 signing keys (ECDSA on P-256 with SHA-256), for a FIPS provider without EdDSA (CRY-01): JWS's
 * 64-byte signatures, the key set's "EC" keys, and the algorithm always the key's, never the
 * signature's.
 */
final class Es256KeyTest extends TestCase
{
    /** P-256's order n, for a high-S signature */
    private const string ORDER = 'ffffffff00000000ffffffffffffffffbce6faada7179e84f3b9cac2fc632551';

    public function testSignsAndVerifiesWithSixtyFourByteSignatures(): void
    {
        $key = SigningKey::generate('es', algorithm: KeyAlgorithm::ES256);
        self::assertSame(KeyAlgorithm::ES256, $key->algorithm);
        self::assertSame(64, \strlen($key->publicKey));

        // Enough signatures that r or s starts with zero bytes, or a high bit, at times.
        for ($i = 0; $i < 300; ++$i) {
            $message = 'checkpoint '.$i;
            $signature = $key->sign($message);

            self::assertSame(64, \strlen($signature));
            self::assertTrue($key->verify($message, $signature));
            self::assertTrue($key->withoutPrivatePart()->verify($message, $signature), 'Without its private part, it still verifies.');
            self::assertFalse($key->verify($message.'!', $signature));
        }

        self::assertFalse($key->verify('m', substr($key->sign('m'), 1)), 'Another length.');
        self::assertFalse($key->verify('m', str_repeat("\0", 64)), 'r and s of zero, which OpenSSL refuses.');
        self::assertFalse($key->verify('m', str_repeat("\xff", 64)), 'r and s beyond the order.');
    }

    public function testAHighSSignatureVerifiesToo(): void
    {
        $key = SigningKey::generate('es', algorithm: KeyAlgorithm::ES256);
        $signature = $key->sign('m');

        // (r, n - s) is the same signature, the other way up: accepted, not normalised.
        self::assertTrue($key->verify('m', substr($signature, 0, 32).self::subtract((string) hex2bin(self::ORDER), substr($signature, 32))));
    }

    // With an Ed25519 key too, which a FIPS provider without EdDSA can't make.
    #[Group('ed25519-keys')]
    public function testTheAlgorithmIsTheKeysNeverTheSignatures(): void
    {
        $edwards = SigningKey::generate('k');
        $elliptic = SigningKey::generate('k', algorithm: KeyAlgorithm::ES256);

        // Same ID, same length: neither key takes the other's signature, and neither throws.
        self::assertFalse(new KeyRing([$edwards])->verify('k', 'm', $elliptic->sign('m')));
        self::assertFalse(new KeyRing([$elliptic])->verify('k', 'm', $edwards->sign('m')));
        self::assertTrue(new KeyRing([$elliptic])->verify('k', 'm', $elliptic->sign('m')));
    }

    // With an Ed25519 key too, which a FIPS provider without EdDSA can't make.
    #[Group('ed25519-keys')]
    public function testTheKeySetKeepsEcKeys(): void
    {
        $ring = new KeyRing([SigningKey::generate('old', KeyStatus::VerifyOnly), SigningKey::generate('new', algorithm: KeyAlgorithm::ES256)]);
        $jwks = json_decode($ring->toJwks(true)->reveal(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($jwks) && \is_array($jwks['keys']) && \is_array($jwks['keys'][1]));

        self::assertSame(['kid', 'kty', 'crv', 'alg', 'use', 'status', 'x', 'y', 'd'], array_keys($jwks['keys'][1]));
        self::assertSame(['EC', 'P-256', 'ES256'], [$jwks['keys'][1]['kty'], $jwks['keys'][1]['crv'], $jwks['keys'][1]['alg']]);

        $read = KeyRing::fromJwks($ring->toJwks(true));
        self::assertSame(KeyAlgorithm::ES256, $read->get('new')?->algorithm);
        self::assertSame(KeyAlgorithm::EdDSA, $read->get('old')?->algorithm);
        self::assertTrue($read->verify('new', 'm', $ring->signer()->sign('m')));
        self::assertTrue(KeyRing::fromJwks($ring->toJwks())->verify('new', 'm', $read->signer()->sign('m')), 'The public set verifies.');
        self::assertSame(KeyAlgorithm::ES256, $read->activating('old')->get('new')?->algorithm, 'Rotating keeps each key\'s algorithm.');
    }

    public function testRefusesPartsThatDontFit(): void
    {
        $key = SigningKey::generate('es', algorithm: KeyAlgorithm::ES256);
        $other = SigningKey::generate('es', algorithm: KeyAlgorithm::ES256);
        \assert(null !== $key->seed && null !== $other->seed);

        foreach ([
            [substr($key->publicKey, 1), $key->seed, 'isn\'t a P-256 public key'],
            [$key->publicKey, Secret::fromString('short'), 'isn\'t a P-256 private key'],
            [$key->publicKey, $other->seed, 'don\'t belong together'],
        ] as [$public, $private, $message]) {
            try {
                new SigningKey('es', KeyStatus::Active, $public, $private, KeyAlgorithm::ES256);
                self::fail('It was made.');
            } catch (IntegratorError $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    /**
     * $a - $b, both 32-byte big-endian numbers, $a the larger.
     */
    private static function subtract(string $a, string $b): string
    {
        $result = '';
        $borrow = 0;

        for ($i = 31; $i >= 0; --$i) {
            $digit = \ord($a[$i]) - \ord($b[$i]) - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $result = \chr(($digit + 256) % 256).$result;
        }

        return $result;
    }
}
