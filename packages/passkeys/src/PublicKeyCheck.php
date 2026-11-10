<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

use CBOR\Decoder;
use CBOR\MapObject;
use CBOR\StringStream;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\EdDSA\Ed25519;
use Cose\Algorithm\Signature\RSA\RS256;
use Cose\Key\Ec2Key;
use Cose\Key\Key;
use Cose\Key\OkpKey;
use Cose\Key\RsaKey;
use YorvoID\Core\Crypto\CryptoPolicy;

/**
 * Whether a passkey's public key, as registration gives it (a COSE key), is one that can verify, and
 * strong enough: the library checks only the algorithm it declares (PK-09).
 *
 * - ES256: an EC2 key on P-256, a point on the curve.
 * - Ed25519: an OKP key on Ed25519, 32 bytes.
 * - RS256: an RSA key of 2048 bits at least (RFC 8230 §6.1), with the exponent 65537.
 *
 * @internal
 */
final class PublicKeyCheck
{
    /**
     * @param list<int> $algorithms The COSE algorithms allowed
     */
    public static function accepts(string $coseKey, array $algorithms = [ES256::ID, Ed25519::ID, RS256::ID]): bool
    {
        try {
            $decoded = Decoder::create()->decode(StringStream::create($coseKey));

            if (!$decoded instanceof MapObject) {
                return false;
            }

            /** @var array<int|string, mixed> $data */
            $data = $decoded->normalize();
            // The decoder gives integers as strings.
            $type = self::integer($data[Key::TYPE] ?? null);

            $algorithm = self::integer($data[Key::ALG] ?? null);

            if (!\in_array($algorithm, $algorithms, true)) {
                return false;
            }

            return match ($algorithm) {
                ES256::ID => Key::TYPE_EC2 === $type && self::ec2(Ec2Key::create($data)),
                Ed25519::ID => Key::TYPE_OKP === $type && self::okp(OkpKey::create($data)),
                RS256::ID => Key::TYPE_RSA === $type && self::rsa(RsaKey::create($data)),
                default => false,
            };
        } catch (\Throwable) {
            return false;
        }
    }

    private static function integer(mixed $value): ?int
    {
        $integer = filter_var($value, \FILTER_VALIDATE_INT);

        return false === $integer ? null : $integer;
    }

    private static function ec2(Ec2Key $key): bool
    {
        // Ec2Key refuses coordinates of another length than the curve's, and OpenSSL a point off the curve.
        return Ec2Key::CURVE_P256 === $key->curveId() && false !== openssl_pkey_get_public($key->asPEM());
    }

    private static function okp(OkpKey $key): bool
    {
        return OkpKey::CURVE_ED25519 === $key->curveId() && 32 === \strlen($key->x());
    }

    private static function rsa(RsaKey $key): bool
    {
        return \strlen(ltrim($key->n(), "\0")) * 8 >= CryptoPolicy::MIN_RSA_BITS && "\x01\x00\x01" === $key->e();
    }
}
