<?php

declare(strict_types=1);

namespace YorvoID\Core\Crypto;

/**
 * What the OpenSSL that PHP runs with offers, as probed: whether only a FIPS provider is active (MD5,
 * which no FIPS provider has, is refused), and whether it has what YorvoID uses. A probe shows what's
 * available, not what's approved: that's in the provider's certificate and security policy.
 *
 * @api
 */
final readonly class CryptoProvider
{
    /** RFC 8032 §7.1, test 2: its seed, public key, message and signature */
    private const array ED25519 = [
        '4ccd089b28ff96da9db6c346ec114e0f5b8a319f35aba624da8cf6ed4fb8a6fb',
        '3d4017c3e843895a92b70aa74d1b7ebc9c982ccf2ec4968cc0cd55f12af4660c',
        '72',
        '92a009a9f0d4cab8720e820b5f642540a2b27b5416503f8fb3762223ebdb69da085ac1e43e15996e458f3613d0f11d8c387b2eaeb4302aeeb00d291612bb0c00',
    ];

    /**
     * @param string    $openssl    OpenSSL's version, as it gives it
     * @param bool      $fipsOnly   Whether it refused MD5: only a FIPS provider is active
     * @param bool      $sha256     Whether it computes SHA-256
     * @param bool      $ed25519    Whether it signs and verifies Ed25519 as RFC 8032 says
     * @param bool|null $kernelFips Whether the kernel runs in FIPS mode (Linux), null when it can't tell
     */
    public function __construct(
        public string $openssl,
        public bool $fipsOnly,
        public bool $sha256,
        public bool $ed25519,
        public ?bool $kernelFips,
    ) {
    }

    /**
     * Probes the OpenSSL that PHP runs with.
     *
     * @param string $kernel Where the kernel says whether it's in FIPS mode
     */
    public static function probe(string $kernel = '/proc/sys/crypto/fips_enabled'): self
    {
        $fips = is_readable($kernel) ? file_get_contents($kernel) : false;

        return new self(
            \OPENSSL_VERSION_TEXT,
            false === self::quietly(static fn (): string|false => openssl_digest('', 'md5')),
            hash('sha256', 'abc') === self::quietly(static fn (): string|false => openssl_digest('abc', 'sha256')),
            self::quietly(self::ed25519(...)),
            \is_string($fips) && \in_array(trim($fips), ['0', '1'], true) ? '1' === trim($fips) : null,
        );
    }

    private static function ed25519(): bool
    {
        [$seed, $public, $message, $signature] = array_map(static fn (string $hex): string => (string) hex2bin($hex), self::ED25519);
        $private = openssl_pkey_new(['ed25519' => ['priv_key' => $seed]]);
        $verifying = openssl_pkey_new(['ed25519' => ['pub_key' => $public]]);

        return false !== $private && false !== $verifying
            && openssl_sign($message, $signed, $private, 0) && $signed === $signature
            && 1 === openssl_verify($message, $signature, $verifying, 0)
            && 0 === openssl_verify('x', $signature, $verifying, 0);
    }

    /**
     * Runs it with OpenSSL's warnings kept quiet: what's refused is the answer, not an error.
     *
     * @template T
     *
     * @param \Closure(): T $probe
     *
     * @return T|false
     */
    private static function quietly(\Closure $probe): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $probe();
        } catch (\Throwable) {
            return false;
        } finally {
            restore_error_handler();
            // What the probe left in OpenSSL's error queue isn't anyone else's error.
            while (false !== openssl_error_string()) {
            }
        }
    }
}
