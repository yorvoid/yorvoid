<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Crypto;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Crypto\CryptoMode;
use YorvoID\Core\Crypto\CryptoPolicy;
use YorvoID\Core\Crypto\CryptoProvider;
use YorvoID\Core\Exception\IntegratorError;

/**
 * The crypto policy (CRY-01): the standard mode allows every algorithm YorvoID supports; the FIPS mode
 * only starts with a FIPS provider alone active, and allows EdDSA only if that provider has it.
 */
final class CryptoPolicyTest extends TestCase
{
    public function testTheStandardModeAllowsEveryAlgorithm(): void
    {
        $standard = CryptoPolicy::standard();

        self::assertSame(CryptoMode::Standard, $standard->mode);
        self::assertSame([-7, -8, -257], $standard->coseAlgorithms());
        self::assertTrue($standard->allowsEdDsa());
        self::assertEquals($standard, CryptoPolicy::of('standard'));
    }

    public function testTheFipsModeNeedsAFipsProviderAlone(): void
    {
        $fips = CryptoPolicy::fips(self::provider(fipsOnly: true, ed25519: true));
        self::assertSame(CryptoMode::Fips, $fips->mode);
        self::assertSame([-7, -8, -257], $fips->coseAlgorithms(), 'A provider with Ed25519 (FIPS 186-5).');
        self::assertSame('OpenSSL 3.5.4', $fips->provider()->openssl, 'The provider it was given, not probed again.');

        self::assertSame([-7, -257], CryptoPolicy::fips(self::provider(fipsOnly: true, ed25519: false))->coseAlgorithms(), 'Without it, no EdDSA.');
        self::assertFalse(CryptoPolicy::fips(self::provider(fipsOnly: true, ed25519: false))->allowsEdDsa());

        foreach ([[false, true, 'it computed MD5'], [true, false, 'doesn\'t compute SHA-256']] as [$fipsOnly, $sha256, $why]) {
            try {
                CryptoPolicy::fips(self::provider(fipsOnly: $fipsOnly, sha256: $sha256));
                self::fail('It started.');
            } catch (IntegratorError $e) {
                self::assertStringContainsString('The FIPS crypto mode is configured, but OpenSSL 3.5.4', $e->getMessage());
                self::assertStringContainsString($why, $e->getMessage());
                self::assertSame('concepts/cryptography.md#fips-mode', $e->docs);
            }
        }
    }

    public function testThisOpensslIsWhatTheImageSays(): void
    {
        $provider = CryptoProvider::probe('/nonexistent');

        self::assertSame(\OPENSSL_VERSION_TEXT, $provider->openssl);
        self::assertTrue($provider->sha256);
        self::assertNull($provider->kernelFips, 'It can\'t tell.');
        self::assertFalse(openssl_error_string(), 'The probe leaves nothing in OpenSSL\'s error queue.');
        self::assertEquals($provider, CryptoPolicy::standard()->provider(), 'The standard mode probes when asked.');

        if (self::fips()) {
            self::assertTrue($provider->fipsOnly, 'The FIPS-configured image runs its FIPS provider alone.');
            self::assertSame(CryptoMode::Fips, CryptoPolicy::of(CryptoMode::Fips)->mode, 'The FIPS mode starts.');
            self::assertSame($provider->ed25519, CryptoPolicy::of(CryptoMode::Fips)->allowsEdDsa());

            return;
        }

        self::assertFalse($provider->fipsOnly, 'The development image runs the default provider.');
        self::assertTrue($provider->ed25519);
        $this->expectException(IntegratorError::class);
        CryptoPolicy::of(CryptoMode::Fips);
    }

    public function testTheKernelSaysWhetherItsInFipsMode(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fips');
        \assert(\is_string($file));

        try {
            foreach (["1\n" => true, "0\n" => false, '2' => null, '' => null] as $content => $kernel) {
                file_put_contents($file, (string) $content);
                self::assertSame($kernel, CryptoProvider::probe($file)->kernelFips, var_export($content, true));
            }
        } finally {
            unlink($file);
        }
    }

    private static function provider(bool $fipsOnly = true, bool $sha256 = true, bool $ed25519 = true): CryptoProvider
    {
        return new CryptoProvider('OpenSSL 3.5.4', $fipsOnly, $sha256, $ed25519, true);
    }

    /**
     * Whether the tests run in the FIPS-configured image (docker/fips).
     */
    private static function fips(): bool
    {
        return '1' === getenv('YORVOID_TEST_FIPS');
    }
}
