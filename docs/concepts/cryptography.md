# Cryptography

YorvoID signs and hashes through OpenSSL, in every configuration. The crypto mode decides which algorithms are allowed, not which code runs (CRY-01).

## What's used where

| What | Algorithm | Through |
|---|---|---|
| Signing keys: the security log's checkpoints ([keys](keys.md)) | Ed25519 (EdDSA, RFC 8032), or ECDSA on P-256 (ES256) | OpenSSL |
| Tokens, codes, the security log's chain, decision records, intents | SHA-256 | OpenSSL (`Digest`) |
| Passkeys, and the intent verifier's keys | ES256 (P-256), EdDSA (Ed25519), RS256 (RSA ≥ 2048 bits) | OpenSSL; the WebAuthn library verifies EdDSA with libsodium |
| Random values | the kernel's CSPRNG | `random_bytes()` |

Some hashes aren't security functions, and stay on PHP's own `hash()`: cache keys, the migrations' lock, the sampling of recorded decisions.

`yorvoid/core` requires `ext-openssl`. `yorvoid/passkeys` and `yorvoid/intent-verifier` also require `ext-sodium`, which EdDSA passkeys need in the standard mode.

Signing keys from before YorvoID 0.4 carry on as they are. OpenSSL makes the same public key from the same seed as libsodium did, and the same signatures.

## The standard mode

The default. Every algorithm above is allowed.

## FIPS mode

```yaml
yorvoid:
    crypto:
        mode: fips
```

In plain PHP, `CryptoPolicy::fips()`, passed to `PasskeyPolicy` (`crypto:`).

In FIPS mode, YorvoID uses only what OpenSSL's provider offers, and it refuses to start unless that provider is a FIPS provider and no other is active. At boot it **probes** OpenSSL:

- **MD5 must fail.** No FIPS provider has it, so an OpenSSL that computes it has another provider active. YorvoID then refuses to start (`IntegratorError`).
- **SHA-256 must work.**
- **Ed25519 decides EdDSA.** A known RFC 8032 vector must sign and verify. EdDSA is in FIPS 186-5, but not every FIPS provider has it. Without it:
  - passkeys and the verifier offer only ES256 and RS256;
  - passkeys already made with EdDSA can't sign in, so their owners need to add another;
  - YorvoID's Ed25519 signing keys can't sign or verify the security log's checkpoints. Use ES256 keys, moved to in the standard mode first ([moving to ES256](keys.md#moving-to-es256)). With an Ed25519 key active, YorvoID **refuses to start**: checkpoints that silently stopped would leave the log open to a rewrite (AUD-003).

For the intent verifier, which runs on its own, leave EdDSA out of `VerifierConfig`'s `algorithms` under such a provider: `algorithms: [-7, -257]`.

A probe shows what's **available**, not what's **approved**. YorvoID uses a validated provider when you configure one; it isn't validated itself. Whether your provider is validated, and installed and configured as its security policy says, is for you to check against its certificate (CMVP). Neither YorvoID nor `yorvoid:doctor` says "approved".

### Configuring OpenSSL

Use your operating system's validated OpenSSL in FIPS mode, e.g. RHEL 9 with `fips-mode-setup --enable` or Ubuntu Pro's FIPS packages. The kernel then runs with `fips=1`. Otherwise, install OpenSSL's FIPS provider (`openssl fipsinstall`) and activate it alone:

```ini
# openssl.cnf
[openssl_init]
providers = provider_sect
alg_section = algorithm_sect

[provider_sect]
fips = fips_sect
base = base_sect

[base_sect]
activate = 1

[algorithm_sect]
default_properties = fips=yes
```

### What YorvoID's tests show

- **CI** runs YorvoID's tests in a **FIPS-configured** container (`docker/fips`), not a FIPS-enabled operating system: GitHub's runners can't boot with `fips=1`.
  - The provider is OpenSSL 3.1.2's FIPS provider, a validated provider: FIPS 140-3, [CMVP certificate #4985](https://csrc.nist.gov/projects/cryptographic-module-validation-program/certificate/4985).
  - It's built from the release tarball, checked against its published SHA-256, installed with its self-test, and activated alone.
  - It has no EdDSA. The job checks that YorvoID refuses to start in FIPS mode with an active Ed25519 key, and starts with an ES256 one. The tests of ES256 keys, checkpoints and anchors run there, and those that need Ed25519 keys are left out.
- **Before a release**, a recorded run on a FIPS-enabled OS with the vendor's validated OpenSSL. It records `fips_enabled=1` and the provider's version.

## yorvoid:doctor

`yorvoid:doctor` reports the mode, OpenSSL's version, whether only a FIPS provider was found, and whether the kernel runs in FIPS mode:

```
 ✔ Cryptography: fips mode, OpenSSL 3.0.7 1 Nov 2022, only a FIPS provider active, kernel in FIPS mode
```

It warns when:

- the kernel runs in FIPS mode but YorvoID's mode is standard;
- in FIPS mode, the provider lacks Ed25519.

## Not YorvoID's

Symfony's own cryptography (`APP_SECRET`, CSRF tokens, password hashers) and the WebAuthn library's internal hashing are outside YorvoID's crypto policy. Check them for your deployment.
