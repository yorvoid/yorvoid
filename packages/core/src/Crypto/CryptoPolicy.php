<?php

declare(strict_types=1);

namespace YorvoID\Core\Crypto;

use YorvoID\Core\Exception\IntegratorError;

/**
 * Which algorithms YorvoID uses (CRY-01). Every mode signs and hashes through OpenSSL, so the mode
 * changes which algorithms are allowed, not which code runs:
 *
 * - Standard: ES256, EdDSA and RS256 for passkeys and the intent verifier's keys.
 * - FIPS: only with a FIPS provider alone active, and EdDSA only if that provider has Ed25519.
 *
 * Minimum sizes in both: RSA keys of 2048 bits at least, ECDSA on P-256.
 *
 * @api
 */
final readonly class CryptoPolicy
{
    /** COSE algorithm IDs (RFC 9053): ECDSA with SHA-256 on P-256 */
    public const int ES256 = -7;

    /** EdDSA, here Ed25519 */
    public const int EDDSA = -8;

    /** RSASSA-PKCS1-v1_5 with SHA-256 */
    public const int RS256 = -257;

    public const int MIN_RSA_BITS = 2048;

    private function __construct(
        public CryptoMode $mode,
        private ?CryptoProvider $provider,
    ) {
    }

    /**
     * @param CryptoProvider|null $provider What OpenSSL offers, if known; probed when asked otherwise
     */
    public static function standard(?CryptoProvider $provider = null): self
    {
        return new self(CryptoMode::Standard, $provider);
    }

    /**
     * @param CryptoProvider|null $provider What OpenSSL offers; probed if not given
     *
     * @throws IntegratorError When more than a FIPS provider is active, or it lacks SHA-256
     */
    public static function fips(?CryptoProvider $provider = null): self
    {
        $provider ??= CryptoProvider::probe();

        if (!$provider->fipsOnly) {
            throw new IntegratorError(\sprintf('The FIPS crypto mode is configured, but %s isn\'t running with only a FIPS provider: it computed MD5.', $provider->openssl), 'Configure OpenSSL to activate its FIPS provider alone (default_properties = fips=yes, the default provider not activated), or set the crypto mode to standard.', 'concepts/cryptography.md#fips-mode');
        }

        if (!$provider->sha256) {
            throw new IntegratorError(\sprintf('The FIPS crypto mode is configured, but %s doesn\'t compute SHA-256.', $provider->openssl), 'Check that the FIPS provider is installed as its security policy says (openssl fipsinstall) and loaded.', 'concepts/cryptography.md#fips-mode');
        }

        return new self(CryptoMode::Fips, $provider);
    }

    /**
     * @throws IntegratorError As fips() does
     */
    public static function of(CryptoMode|string $mode): self
    {
        return match (\is_string($mode) ? CryptoMode::from($mode) : $mode) {
            CryptoMode::Standard => self::standard(),
            CryptoMode::Fips => self::fips(),
        };
    }

    /**
     * What OpenSSL offers: as probed for the FIPS mode, probed now otherwise.
     */
    public function provider(): CryptoProvider
    {
        return $this->provider ?? CryptoProvider::probe();
    }

    public function allowsEdDsa(): bool
    {
        return match ($this->mode) {
            CryptoMode::Standard => true,
            CryptoMode::Fips => true === $this->provider?->ed25519,
        };
    }

    /**
     * The COSE algorithms keys may use, in order of preference.
     *
     * @return non-empty-list<int>
     */
    public function coseAlgorithms(): array
    {
        return $this->allowsEdDsa() ? [self::ES256, self::EDDSA, self::RS256] : [self::ES256, self::RS256];
    }
}
