# FIPS runs

Before each release, YorvoID's tests run on an operating system booted in FIPS mode ([what this shows](../docs/concepts/cryptography.md#what-yorvoids-tests-show)). `tools/fips-host/run.sh` does the run on a throwaway AlmaLinux 9 host, and each section here is one run: the release, the commit, the date, and the report the script wrote, unedited. Each run's commit has a `fips-run/<version>` tag, which keeps it after the branch is squash-merged.

A run shows the kernel in FIPS mode, OpenSSL using only the FIPS provider's algorithms, MD5 refused, and the tests passing. It doesn't show that the provider is validated. That's on the provider's CMVP certificate, for the version you deploy.

## 0.4.0

- **Commit:** `9b31482ad7870a7ad093336bd9c7c593c74cd752` (tag `fips-run/0.4.0`; main squash-merges, so the tag keeps it)
- **Date:** 2026-11-11, 09:37 UTC
- **Host:** a throwaway DigitalOcean droplet, AlmaLinux 9.8 booted in FIPS mode by `fips-mode-setup --enable`, destroyed after the run.
- **Provider:** AlmaLinux 9.8's build of the Red Hat Enterprise Linux OpenSSL FIPS Provider 3.5.8. It's not Red Hat's binary, and whether it's validated wasn't checked.
- **Result:** the tests passed, the Ed25519 keys' among them: this provider has Ed25519, so YorvoID's FIPS mode allows EdDSA. The skipped tests need a PostgreSQL server, which the host didn't run.

The report, as `run.sh` wrote it (terminal colour codes removed):

```text
== The run
commit: 9b31482ad7870a7ad093336bd9c7c593c74cd752
date: 2026-11-11T09:37:43Z
working tree: clean
== The operating system
NAME="AlmaLinux"
VERSION="9.8 (Olive Jaguar)"
kernel fips_enabled: 1
FIPS mode is enabled.
FIPS
OpenSSL 3.5.8 25 Aug 2026 (Library: OpenSSL 3.5.8 25 Aug 2026)
Providers:
  base
    name: OpenSSL Base Provider
    version: 3.5.8
    status: active
  default
    name: OpenSSL Default Provider
    version: 3.5.8
    status: active
  fips
    name: Red Hat Enterprise Linux OpenSSL FIPS Provider
    version: 3.5.8-a82ab8b5ba53adf2
    status: active
MD5: refused
== PHP
PHP 8.4.27 (cli) (built: Oct 19 2026 14:38:59) (NTS gcc x86_64)
PHP links OpenSSL: OpenSSL 3.5.5 27 Jan 2026
MD5 from PHP: false
== YorvoID's probe
\YorvoID\Core\Crypto\CryptoProvider::__set_state(array(
   'openssl' => 'OpenSSL 3.5.5 27 Jan 2026',
   'fipsOnly' => true,
   'sha256' => true,
   'ed25519' => true,
   'kernelFips' => true,
))
FIPS policy: starts, EdDSA allowed: true, COSE algorithms: [-7,-8,-257]
== The tests (composer test-fips)
SQLite for the tests: 3.54.1
Ed25519 in the provider: the tests with Ed25519 keys run
PHPUnit 13.4.5 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.27
Configuration: /root/yorvoid/phpunit.dist.xml
Random Seed:   1794389973

Time: 04:23.239, Memory: 473.00 MB

OK, but some tests were skipped!
Tests: 2415, Assertions: 97857, Skipped: 325.
```

### The release commit

The run was on `9b31482`; 0.4.0 is released from a later commit. The run still covers it, because nothing in between changes a cryptographic primitive, an algorithm or a third-party package that runs in production. Otherwise the release would wait for a new run. Checked on `d42382c`, main as the release branch started from it, and again on the release commit before it's tagged. The release branch itself changes only the changelog, the docs and this page.

The paths that hold YorvoID's cryptography, the run's script and the dependencies:

```sh
git diff --quiet 9b31482 d42382c -- packages/core/src/Crypto packages/core/src/Key packages/core/src/Log packages/core/src/Log/Checkpoint packages/passkeys/src tools/fips-host composer.json composer.lock
```

It exits 1: they differ, and only as follows.

- `packages/core/src/Crypto`, `Key`, `Log`, `Log/Checkpoint` and `tools/fips-host`: unchanged.
- `packages/passkeys/src`, seven files: the recovery pages' wording, the notice of a used recovery code and the cool-down they show, and `Permit::about()` before a sign-in starts its session. No call to OpenSSL, sodium, a hash or a random source, and no COSE algorithm.
- `composer.json`: `symfony/expression-language` in `require-dev`.
- `composer.lock`: every third-party package in `packages`, the ones installed in production, is at the same version from the same source. YorvoID's own packages there (`yorvoid/core`, `authn`, `passkeys`, `symfony`, `intent-verifier`) changed reference: they're this repository, covered by the paths above and the search below. Only `packages-dev` changed: `composer/semver` 3.6.0, `ergebnis/agent-detector` 2.0.0, `friendsofphp/php-cs-fixer` 3.95.28, `infection/infection` 0.35.9, `phpstan/phpstan` 2.3.5, `phpstan/phpstan-strict-rules` 2.1.1, `sanmai/di-container` 0.2.2, `sanmai/duoclock` 0.1.4, and the new `symfony/expression-language` 8.1.9.

And across all of `packages/*/src`, not only those paths, the lines added or removed since the run that name a cryptographic function:

```sh
git diff 9b31482 d42382c -- packages | grep '^[+-]' | grep -v '^+++\|^---' | grep -E 'openssl_|sodium_|hash_hmac|hash_equals|hash\(|random_bytes|random_int|password_|CryptoPolicy|CryptoProvider'
```

One pair of lines: `packages/core/src/Storage/VersionedCache.php:136`, the cache key, where `hash('sha256', …)` now takes the read's encoded arguments. The removed line had the same `hash('sha256', …)`. It's SHA-256, which the run's tests already used, through PHP's own implementation, for a key that isn't secret.
