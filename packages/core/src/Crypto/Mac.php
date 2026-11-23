<?php

declare(strict_types=1);

namespace YorvoID\Core\Crypto;

use YorvoID\Core\Secret;

/**
 * HMAC-SHA256 (RFC 2104), composed over the SHA-256 that OpenSSL computes (Digest), so that with a FIPS
 * provider its hash is the provider's. The composition itself is YorvoID's, outside the provider: PHP
 * offers no HMAC of OpenSSL's, and its own hash_hmac() isn't the provider's (cryptography.md says so).
 *
 * @api
 */
final class Mac
{
    /** SHA-256's block, in bytes */
    private const int BLOCK = 64;

    /**
     * The 32-byte MAC of a message.
     */
    public static function sha256(Secret $key, string $message): string
    {
        $k = $key->reveal();

        if (\strlen($k) > self::BLOCK) {
            $k = Digest::sha256($k, true);
        }

        $k = str_pad($k, self::BLOCK, "\0");

        return Digest::sha256(($k ^ str_repeat("\x5c", self::BLOCK)).Digest::sha256(($k ^ str_repeat("\x36", self::BLOCK)).$message, true), true);
    }
}
