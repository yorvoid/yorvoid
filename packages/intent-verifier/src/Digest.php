<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * SHA-256 through OpenSSL, for what the verifier's security depends on: with a FIPS provider
 * configured, from that provider (CRY-01).
 *
 * @internal
 */
final class Digest
{
    /**
     * @param bool $binary Raw bytes rather than hexadecimal
     *
     * @throws \RuntimeException When OpenSSL doesn't offer SHA-256
     */
    public static function sha256(string $data, bool $binary = false): string
    {
        $digest = openssl_digest($data, 'sha256', $binary);

        if (false === $digest) {
            throw new \RuntimeException(\sprintf('OpenSSL couldn\'t compute sha256: %s', false === ($error = openssl_error_string()) ? 'the algorithm isn\'t available' : $error));
        }

        return $digest;
    }
}
