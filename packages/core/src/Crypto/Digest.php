<?php

declare(strict_types=1);

namespace YorvoID\Core\Crypto;

/**
 * The hashes YorvoID's security depends on (tokens, codes, the security log's chain, decision records),
 * through OpenSSL in every mode: with a FIPS provider configured, from that provider (CRY-01). PHP's own
 * hash() is for what security doesn't depend on, e.g. cache keys.
 *
 * @api
 */
final class Digest
{
    /** The algorithms it computes */
    public const array ALGORITHMS = ['sha256'];

    /**
     * @param string $algorithm One of ALGORITHMS
     * @param bool   $binary    Raw bytes rather than hexadecimal
     *
     * @throws \RuntimeException When it isn't one of them, or OpenSSL doesn't offer it
     */
    public static function of(string $algorithm, string $data, bool $binary = false): string
    {
        if (!\in_array($algorithm, self::ALGORITHMS, true)) {
            throw new \RuntimeException(\sprintf('YorvoID doesn\'t hash with %s.', $algorithm));
        }

        $digest = openssl_digest($data, $algorithm, $binary);

        if (false === $digest) {
            throw new \RuntimeException(\sprintf('OpenSSL couldn\'t compute %s: %s', $algorithm, false === ($error = openssl_error_string()) ? 'the algorithm isn\'t available' : $error));
        }

        return $digest;
    }

    /**
     * SHA-256, in hexadecimal unless $binary.
     */
    public static function sha256(string $data, bool $binary = false): string
    {
        return self::of('sha256', $data, $binary);
    }
}
