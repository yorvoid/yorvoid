<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Key;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Key\KeyStatus;
use YorvoID\Core\Key\MacKey;
use YorvoID\Core\Key\SigningKey;
use YorvoID\Core\Secret;

/**
 * MAC keys in the key ring, apart from the signing keys by type: nothing signs or verifies with one, a key
 * ID is its own across both kinds, and an HMAC key can't pass for a signing key (the JOSE algorithm
 * confusion).
 */
final class MacKeysTest extends TestCase
{
    public function testTheRingMacsWithItsActiveMacKeyOnly(): void
    {
        $ring = new KeyRing([SigningKey::generate('s-1')], [MacKey::generate('m-1')]);

        self::assertSame('m-1', $ring->mac()?->id);
        self::assertSame('m-1', $ring->macKey('m-1')?->id);
        self::assertNull($ring->get('m-1'), 'A MAC key is never a signing key.');
        self::assertNull($ring->macKey('s-1'), 'Nor a signing key a MAC key.');
        self::assertSame(['s-1'], array_map(static fn (SigningKey $key): string => $key->id, $ring->keys()));
        self::assertNull(new KeyRing([SigningKey::generate('s-1')])->mac(), 'A ring may have none.');
    }

    /**
     * verify() with a MAC key's ID is false, even for that key's own HMAC of the message.
     */
    public function testAMacKeysIdVerifiesNoSignature(): void
    {
        $mac = MacKey::generate('m-1');
        $ring = new KeyRing([SigningKey::generate('s-1')], [$mac]);

        self::assertFalse($ring->verify('m-1', 'message', $mac->mac('message')));
        self::assertFalse($ring->verify('m-1', 'message', $mac->mac('message').$mac->mac('message')));
    }

    public function testMacKeysRoundTripOnlyInTheKeyFile(): void
    {
        $retired = MacKey::generate('m-1')->retired(new \DateTimeImmutable('2026-11-01T00:00:00+00:00'));
        $ring = new KeyRing([SigningKey::generate('s-1')], [$retired, MacKey::generate('m-2')]);
        $copy = KeyRing::fromJwks($ring->toJwks(withPrivateParts: true));

        self::assertSame('m-2', $copy->mac()?->id);
        self::assertSame([KeyStatus::VerifyOnly, '2026-11-01T00:00:00+00:00'], [$copy->macKey('m-1')?->status, $copy->macKey('m-1')?->since?->format(\DATE_ATOM)]);
        self::assertSame($retired->mac('x'), $copy->macKey('m-1')?->mac('x'));
        self::assertStringNotContainsString('"oct"', $ring->toJwks()->reveal(), 'The public set has no MAC key: it has no public part.');
        self::assertSame([], KeyRing::fromJwks($ring->toJwks())->macKeys());
        self::assertStringContainsString('"key_ops": [', $ring->toJwks(withPrivateParts: true)->reveal());
    }

    public function testAMacKeyRotatesInOneStepAndIsRemovedOnlyOnceRetired(): void
    {
        $now = new \DateTimeImmutable('2026-11-23T18:00:00+00:00');
        $ring = new KeyRing([SigningKey::generate('s-1')], [MacKey::generate('m-1')])->withMac(MacKey::generate('m-2'), $now);

        self::assertSame('m-2', $ring->mac()?->id);
        self::assertSame([KeyStatus::VerifyOnly, $now], [$ring->macKey('m-1')?->status, $ring->macKey('m-1')?->since]);
        self::assertSame(['m-1', 'm-2'], array_map(static fn (MacKey $key): string => $key->id, $ring->with(SigningKey::generate('s-2', KeyStatus::VerifyOnly))->activating('s-2')->without('s-1')->macKeys()), 'Rotating the signing keys keeps the MAC keys.');
        self::assertNull($ring->withoutMac('m-1')->macKey('m-1'));

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('The MAC key "m-2" is active: it can\'t be removed.');
        $ring->withoutMac('m-2');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        $k = rtrim(strtr(base64_encode(str_repeat("\x01", 32)), '+/', '-_'), '=');
        $oct = static fn (array $more): string => json_encode(['keys' => [['kid' => 'm-1', 'kty' => 'oct', 'alg' => 'HS256', 'status' => 'active', 'k' => $k, ...$more]]], \JSON_THROW_ON_ERROR);

        yield 'an oct key that says it signs' => [$oct(['use' => 'sig']), 'The key "m-1" is an "oct" key, but not a MAC key as YorvoID keeps them.'];
        yield 'an oct key of another algorithm' => [$oct(['alg' => 'HS512']), 'The key "m-1" is an "oct" key, but not a MAC key as YorvoID keeps them.'];
        yield 'an oct key for something else' => [$oct(['key_ops' => ['encrypt']]), 'The key "m-1" is an "oct" key, but not a MAC key as YorvoID keeps them.'];
        yield 'a MAC key that isn\'t 32 bytes' => [$oct(['k' => 'AQID']), 'The MAC key "m-1" isn\'t 32 bytes.'];
        yield 'a MAC key without a status' => [$oct(['status' => 'maybe']), 'The key "m-1" has no valid status.'];
        yield 'a MAC key retired at no time' => [$oct(['since' => 'yesterday']), 'The MAC key "m-1" has a "since" that isn\'t a time.'];
        yield 'a signing entry with an HS algorithm' => [json_encode(['keys' => [['kid' => 's-1', 'kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'HS256', 'status' => 'active', 'x' => $k]]], \JSON_THROW_ON_ERROR), 'The signing key "s-1" has the MAC algorithm HS256.'];
    }

    #[DataProvider('refused')]
    public function testRefusesWhatIsntAMacKeyAsYorvoIdKeepsThem(string $json, string $message): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage($message);
        KeyRing::fromJwks(Secret::fromString($json));
    }

    public function testAKeyIdIsItsOwnAcrossBothKindsAndOneMacKeyIsActive(): void
    {
        foreach ([
            'Two keys have the ID "k-1".' => static fn (): KeyRing => new KeyRing([SigningKey::generate('k-1')], [MacKey::generate('k-1')]),
            'Two keys have the ID "m-1".' => static fn (): KeyRing => new KeyRing([], [MacKey::generate('m-1'), MacKey::generate('m-1')->retired(new \DateTimeImmutable())]),
            'The key ring has 2 active MAC keys (m-1, m-2).' => static fn (): KeyRing => new KeyRing([SigningKey::generate('s-1')], [MacKey::generate('m-1'), MacKey::generate('m-2')]),
        ] as $message => $ring) {
            try {
                $ring();
                self::fail('Accepted: '.$message);
            } catch (IntegratorError $e) {
                self::assertStringStartsWith($message."\n", $e->getMessage());
            }
        }
    }
}
