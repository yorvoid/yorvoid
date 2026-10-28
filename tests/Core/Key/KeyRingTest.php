<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Key;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Key\KeyStatus;
use YorvoID\Core\Key\SigningKey;
use YorvoID\Core\Secret;

final class KeyRingTest extends TestCase
{
    /** RFC 8032, section 7.1, TEST 1 and TEST 2 */
    private const string SEED_1 = '9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60';

    private const string PUBLIC_1 = 'd75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a';

    private const string SIGNATURE_1 = 'e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b';

    private const string SEED_2 = '4ccd089b28ff96da9db6c346ec114e0f5b8a319f35aba624da8cf6ed4fb8a6fb';

    private const string PUBLIC_2 = '3d4017c3e843895a92b70aa74d1b7ebc9c982ccf2ec4968cc0cd55f12af4660c';

    private const string MESSAGE_2 = '72';

    private const string SIGNATURE_2 = '92a009a9f0d4cab8720e820b5f642540a2b27b5416503f8fb3762223ebdb69da085ac1e43e15996e458f3613d0f11d8c387b2eaeb4302aeeb00d291612bb0c00';

    public function testSignsAsRfc8032Specifies(): void
    {
        $first = self::rfcKey(self::SEED_1, self::PUBLIC_1);
        $second = self::rfcKey(self::SEED_2, self::PUBLIC_2);

        self::assertSame(self::SIGNATURE_1, bin2hex($first->sign('')));
        self::assertSame(self::SIGNATURE_2, bin2hex($second->sign((string) hex2bin(self::MESSAGE_2))));
        self::assertTrue($first->verify('', (string) hex2bin(self::SIGNATURE_1)));
        self::assertTrue($second->verify((string) hex2bin(self::MESSAGE_2), (string) hex2bin(self::SIGNATURE_2)));
    }

    public function testVerificationFailsForAnythingElse(): void
    {
        $key = SigningKey::generate('k1');
        $other = SigningKey::generate('k2');
        $signature = $key->sign('message');

        self::assertTrue($key->verify('message', $signature));
        self::assertFalse($key->verify('messagf', $signature));
        self::assertFalse($other->verify('message', $signature));
        self::assertFalse($key->verify('message', substr($signature, 0, 63)));
        self::assertFalse($key->verify('message', $signature.'x'));
        self::assertFalse($key->verify('message', ''));
    }

    public function testOneKeySignsAndAllVerify(): void
    {
        $old = SigningKey::generate('2026-09', KeyStatus::VerifyOnly);
        $new = SigningKey::generate('2026-10');
        $upcoming = SigningKey::generate('2026-11', KeyStatus::VerifyOnly)->withoutPrivatePart();
        $ring = new KeyRing([$old, $new, $upcoming]);

        self::assertSame('2026-10', $ring->signer()->id);
        self::assertTrue($ring->verify('2026-10', 'message', $ring->signer()->sign('message')));
        self::assertSame($old, $ring->get('2026-09'));
        self::assertNull($ring->get('2026-08'));
        self::assertFalse($ring->verify('2026-08', 'message', $new->sign('message')), 'An unknown key verified.');
        self::assertFalse($ring->verify('2026-09', 'message', $new->sign('message')), 'Another key\'s signature verified.');
        self::assertSame(['2026-09', '2026-10', '2026-11'], array_map(static fn (SigningKey $key): string => $key->id, $ring->keys()));
    }

    public function testVerifyOnlyKeysDontSign(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('isn\'t active, so it doesn\'t sign');

        SigningKey::generate('k', KeyStatus::VerifyOnly)->sign('message');
    }

    public function testAKeyWithoutItsPrivatePartOnlyVerifies(): void
    {
        $key = SigningKey::generate('k');
        $public = $key->withoutPrivatePart();

        self::assertNull($public->seed);
        self::assertSame(KeyStatus::VerifyOnly, $public->status);
        self::assertSame($key->publicKey, $public->publicKey);
        self::assertTrue($public->verify('message', $key->sign('message')));
    }

    public function testTheKeySetRoundTrips(): void
    {
        $ring = new KeyRing([SigningKey::generate('2026-10'), SigningKey::generate('2026-09', KeyStatus::VerifyOnly)]);
        $copy = KeyRing::fromJwks($ring->toJwks(withPrivateParts: true));

        self::assertSame('2026-10', $copy->signer()->id);
        self::assertTrue($ring->verify('2026-10', 'message', $copy->signer()->sign('message')));
        self::assertTrue($copy->verify('2026-09', 'message', self::signWith($ring->keys()[1], 'message')));
        self::assertNotNull($copy->get('2026-09')?->seed, 'The private part of a verify-only key was lost.');
    }

    public function testThePublicKeySetHasNoPrivateParts(): void
    {
        $json = new KeyRing([self::rfcKey(self::SEED_1, self::PUBLIC_1)])->toJwks()->reveal();
        $set = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);

        self::assertSame(['keys' => [['kid' => 'rfc', 'kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'EdDSA', 'use' => 'sig', 'status' => 'active', 'x' => '11qYAYKxCrfVS_7TyWQHOg7hcvPapiMlrwIaaPcHURo']]], $set);
    }

    public function testAcceptsTheStandardsOwnLists(): void
    {
        $ring = KeyRing::fromJwks(Secret::fromString('{"keys":[{"kty":"OKP","crv":"Ed25519","kid":"k","status":"active","key_ops":["sign","verify"],"d":"nWGxne_9WmC6hEr0kuwsxERJxWl7MmkZcDusAxyuf2A","x":"11qYAYKxCrfVS_7TyWQHOg7hcvPapiMlrwIaaPcHURo"}]}'));

        self::assertSame('k', $ring->signer()->id);
    }

    public function testWritesTheKeySetForPeopleToRead(): void
    {
        self::assertStringStartsWith("{\n    \"keys\": [\n        {\n            \"kid\": \"rfc\"", new KeyRing([self::rfcKey(self::SEED_1, self::PUBLIC_1)])->toJwks()->reveal());
    }

    public function testReadsAKeyFromItsStandardForm(): void
    {
        // RFC 8037, appendix A.1 and A.2: the same key as RFC 8032's TEST 1.
        $ring = KeyRing::fromJwks(Secret::fromString('{"keys":[{"kty":"OKP","crv":"Ed25519","kid":"rfc8037","status":"active","d":"nWGxne_9WmC6hEr0kuwsxERJxWl7MmkZcDusAxyuf2A","x":"11qYAYKxCrfVS_7TyWQHOg7hcvPapiMlrwIaaPcHURo"}]}'));

        self::assertSame(self::SIGNATURE_1, bin2hex($ring->signer()->sign('')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidSets(): iterable
    {
        $x = '11qYAYKxCrfVS_7TyWQHOg7hcvPapiMlrwIaaPcHURo';
        $d = 'nWGxne_9WmC6hEr0kuwsxERJxWl7MmkZcDusAxyuf2A';
        $key = static fn (array $changes): string => json_encode(['keys' => [array_filter([...['kid' => 'k', 'kty' => 'OKP', 'crv' => 'Ed25519', 'status' => 'active', 'x' => $x, 'd' => $d], ...$changes], static fn ($value): bool => null !== $value)]], \JSON_THROW_ON_ERROR);

        yield 'not JSON' => ['{"keys": [', 'isn\'t valid JSON'];
        yield 'no keys' => ['{"other": []}', 'has no list of keys'];
        yield 'keys not a list' => ['{"keys": {"a": {}}}', 'has no list of keys'];
        yield 'a key that isn\'t an object' => ['{"keys": ["k"]}', 'isn\'t a key'];
        yield 'an RSA key' => [$key(['kty' => 'RSA']), 'isn\'t an Ed25519 key'];
        yield 'another curve' => [$key(['crv' => 'X25519']), 'isn\'t an Ed25519 key'];
        yield 'another algorithm' => [$key(['alg' => 'ES256']), 'isn\'t an Ed25519 key'];
        yield 'no status' => [$key(['status' => null]), 'has no valid status'];
        yield 'an unknown status' => [$key(['status' => 'retired']), 'has no valid status'];
        yield 'no public part' => [$key(['x' => null]), 'isn\'t base64url'];
        yield 'padded base64' => [$key(['x' => $x.'=']), 'isn\'t base64url'];
        yield 'standard base64' => [$key(['x' => '11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo']), 'isn\'t base64url'];
        yield 'a short public part' => [$key(['x' => 'AAAA']), 'isn\'t an Ed25519 public key'];
        yield 'a short seed' => [$key(['d' => 'AAAA']), 'isn\'t an Ed25519 seed'];
        yield 'parts that don\'t belong together' => [$key(['d' => 'TM0Imyj_ltqdtsNG7BFOD1uKMZ81q6Yk2oz27U-4pvs']), 'don\'t belong together'];
        yield 'active without a private part' => [$key(['d' => null]), 'active but has no private part'];
        yield 'an invalid ID' => [$key(['kid' => 'key one']), 'key ID "key one" is invalid'];
        yield 'no active key' => [$key(['status' => 'verify-only']), 'has 0 active keys'];
        yield 'a value with a trailing newline' => [$key(['x' => $x."\n"]), 'isn\'t base64url'];
        yield 'nested too deeply' => [$key(['key_ops' => [['sign']]]), 'isn\'t valid JSON'];
    }

    #[DataProvider('invalidSets')]
    public function testRefusesInvalidKeySets(string $json, string $message): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage($message);

        KeyRing::fromJwks(Secret::fromString($json));
    }

    public function testRefusesTwoActiveKeysAndDuplicateIds(): void
    {
        try {
            new KeyRing([SigningKey::generate('a'), SigningKey::generate('b')]);
            self::fail('Two active keys were accepted.');
        } catch (IntegratorError $error) {
            self::assertStringContainsString('has 2 active keys (a, b)', $error->problem);
        }

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('Two keys have the ID "a"');

        new KeyRing([SigningKey::generate('a'), SigningKey::generate('a', KeyStatus::VerifyOnly)]);
    }

    public function testReadsAFileOnlyItsOwnerCanRead(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'yorvoid-keys-');
        \assert(\is_string($path));

        try {
            file_put_contents($path, new KeyRing([SigningKey::generate('2026-10')])->toJwks(withPrivateParts: true)->reveal());
            chmod($path, 0o600);
            self::assertSame('2026-10', KeyRing::fromFile($path)->signer()->id);

            chmod($path, 0o640);
            try {
                KeyRing::fromFile($path);
                self::fail('A key file others can read was accepted.');
            } catch (IntegratorError $error) {
                self::assertStringContainsString('can be read by others than its owner (mode 640)', $error->problem);
                self::assertSame('Run: chmod 600 '.$path, $error->fix);
            }

            foreach ([0o604, 0o601, 0o610] as $mode) {
                chmod($path, $mode);

                try {
                    KeyRing::fromFile($path);
                    self::fail(\sprintf('A key file with mode %o was accepted.', $mode));
                } catch (IntegratorError $error) {
                    self::assertStringContainsString(\sprintf('(mode %o)', $mode), $error->problem);
                }
            }

            chmod($path, 0o641);
            $this->expectException(IntegratorError::class);
            $this->expectExceptionMessage('(mode 641)');
            KeyRing::fromFile($path);
        } finally {
            unlink($path);
        }
    }

    public function testReportsAMissingFile(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('can\'t be read');

        KeyRing::fromFile('/nonexistent/yorvoid-keys.json');
    }

    public function testPrivatePartsStaySecret(): void
    {
        $key = self::rfcKey(self::SEED_1, self::PUBLIC_1);

        self::assertStringNotContainsString(self::SEED_1, bin2hex(print_r($key, true)));
        self::assertStringNotContainsString('nWGxne', print_r($key, true));
    }

    private static function rfcKey(string $seed, string $public): SigningKey
    {
        return new SigningKey('rfc', KeyStatus::Active, (string) hex2bin($public), Secret::fromString((string) hex2bin($seed)));
    }

    private static function signWith(SigningKey $key, string $message): string
    {
        return new SigningKey($key->id, KeyStatus::Active, $key->publicKey, $key->seed)->sign($message);
    }
}
