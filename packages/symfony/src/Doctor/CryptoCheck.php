<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use YorvoID\Core\Crypto\CryptoMode;
use YorvoID\Core\Crypto\CryptoPolicy;

/**
 * Which crypto mode is configured, and what OpenSSL offers (CRY-01): its version, whether only a FIPS
 * provider is active, whether the kernel runs in FIPS mode. Available isn't approved: whether the
 * provider is validated is for the operator to check against its certificate.
 *
 * @internal
 */
final readonly class CryptoCheck implements Check, Reports
{
    public function __construct(
        private CryptoMode $mode,
        /** @var \Closure(): CryptoPolicy */
        private \Closure $policy,
    ) {
    }

    public function summary(): string
    {
        $provider = ($this->policy)()->provider();

        return \sprintf('%s mode, %s%s%s', $this->mode->value, $provider->openssl, $provider->fipsOnly ? ', only a FIPS provider active' : '', match ($provider->kernelFips) {
            true => ', kernel in FIPS mode',
            false => ', kernel not in FIPS mode',
            null => '',
        });
    }

    public function findings(): iterable
    {
        // In the FIPS mode, the policy refuses an OpenSSL that doesn't fit it: the doctor reports that.
        $policy = ($this->policy)();
        $provider = $policy->provider();

        if (CryptoMode::Standard === $this->mode && true === $provider->kernelFips) {
            yield Finding::warning('The kernel runs in FIPS mode, but YorvoID\'s crypto mode is standard: it offers algorithms a FIPS provider may not have.', 'Set yorvoid.crypto.mode: fips if the deployment must use only the FIPS provider.', 'concepts/cryptography.md#fips-mode');
        }

        if (CryptoMode::Fips === $this->mode && !$provider->ed25519) {
            // With signing keys configured, the policy itself refuses (Factories::cryptoPolicy()).
            yield Finding::warning('The FIPS provider has no Ed25519: passkeys made with EdDSA can\'t sign in, and new ones use ES256 or RS256.', 'Ask the people with EdDSA passkeys to add another passkey, or use a FIPS provider with EdDSA (FIPS 186-5).', 'concepts/cryptography.md#fips-mode');
        }
    }
}
