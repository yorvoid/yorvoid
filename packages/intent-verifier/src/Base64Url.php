<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * @internal
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @return string|null The bytes; null if it isn't base64url without padding, written the one way
     *                     encode() writes it
     */
    public static function decode(string $text): ?string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return false === $bytes || self::encode($bytes) !== $text ? null : $bytes;
    }
}
